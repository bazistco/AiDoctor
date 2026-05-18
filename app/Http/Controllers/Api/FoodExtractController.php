<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FoodExtractController extends Controller
{
    private string $ollamaUrl;
    private string $ollamaModel;

    public function __construct()
    {
        $this->ollamaUrl = env('OLLAMA_URL', 'http://localhost:11434/api/generate');
        $this->ollamaModel = env('OLLAMA_MODEL', 'llama3.1:8b');
    }

    public function extract(Request $request)
    {
        $validated = $request->validate([
            'text' => 'required|string|max:5000',
        ]);

        $text = $validated['text'];

        $prompt = <<<PROMPT
You are an expert food entity extraction system.

Your task is to read a sentence and extract ALL foods and their amounts.

Rules:
- Extract ONLY edible food or drink items.
- Convert written numbers to digits (two → 2, three → 3).
- Normalize food names to singular form (eggs → egg).
- If quantity is missing, assume "1 serving".
- Keep the unit if mentioned (grams, glass, cup, slice, piece, bowl, tablespoon, etc).
- If a number exists but no unit, use "pieces".
- Do NOT invent foods that are not mentioned.
- Ignore non-food items.
- Do not include explanations or extra text.

Output STRICTLY valid JSON in this format:

{
  "items": [
    {
      "food": "food name",
      "amount": "number + unit"
    }
  ]
}

Examples:

Text: I ate two eggs and a glass of milk
Output:
{"items":[{"food":"egg","amount":"2 pieces"},{"food":"milk","amount":"1 glass"}]}

Text: breakfast was 150 grams chicken breast with one cup rice
Output:
{"items":[{"food":"chicken breast","amount":"150 grams"},{"food":"rice","amount":"1 cup"}]}

Text: I had a burger, fries and cola
Output:
{"items":[{"food":"burger","amount":"1 serving"},{"food":"fries","amount":"1 serving"},{"food":"cola","amount":"1 serving"}]}

Now extract food items from this text:

TEXT:
{$text}

Return ONLY JSON.
PROMPT;

        try {
            $response = Http::timeout(60)->post($this->ollamaUrl, [
                'model' => $this->ollamaModel,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.1,
                    'num_predict' => 500,
                    'top_p' => 0.9,
                ],
            ]);

            if (!$response->successful()) {
                Log::error('Ollama API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'خطا در ارتباط با سرویس استخراج',
                ], 500);
            }

            $data = $response->json();
            $extractedText = $data['response'] ?? '';

            // استخراج JSON از پاسخ
            if (preg_match('/\{[\s\S]*\}/', $extractedText, $matches)) {
                $jsonString = $matches[0];
                $parsed = json_decode($jsonString, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($parsed['items'])) {
                    // اضافه کردن اطلاعات کالری به هر آیتم
                    $enrichedItems = $this->enrichWithCalories($parsed['items']);

                    return response()->json([
                        'success' => true,
                        'data' => [
                            'items' => $enrichedItems,
                            'total_calories' => array_sum(array_column($enrichedItems, 'total_calories')),
                            'total_protein' => array_sum(array_column($enrichedItems, 'total_protein')),
                            'total_carbs' => array_sum(array_column($enrichedItems, 'total_carbs')),
                            'total_fat' => array_sum(array_column($enrichedItems, 'total_fat')),
                        ],
                        'raw_response' => $extractedText,
                    ]);
                }
            }

            Log::warning('Failed to parse Ollama response', [
                'response' => $extractedText,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'فرمت پاسخ نامعتبر است',
                'raw_response' => $extractedText,
            ], 422);

        } catch (\Exception $e) {
            Log::error('Food extraction error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطای سرور: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function enrichWithCalories(array $items): array
    {
        $enriched = [];

        foreach ($items as $item) {
            $foodName = $item['food'];
            $amount = $item['amount'];

            // جستجو در دیتابیس (فارسی و انگلیسی)
            $foodData = DB::table('foods')
                ->where('name', 'LIKE', "%{$foodName}%")
                ->orWhere('name_en', 'LIKE', "%{$foodName}%")
                ->first();

            if ($foodData) {
                // تبدیل مقدار به گرم
                $grams = $this->convertToGrams($amount);

                // محاسبه کالری و ماکروها
                $multiplier = $grams / 100;

                $enriched[] = [
                    'food' => $foodName,
                    'food_fa' => $foodData->name,
                    'food_en' => $foodData->name_en,
                    'amount' => $amount,
                    'grams' => round($grams, 1),
                    'calories_per_100g' => $foodData->calories_per_100g,
                    'total_calories' => round($foodData->calories_per_100g * $multiplier, 1),
                    'protein_per_100g' => $foodData->protein_per_100g,
                    'total_protein' => round($foodData->protein_per_100g * $multiplier, 1),
                    'carbs_per_100g' => $foodData->carbs_per_100g,
                    'total_carbs' => round($foodData->carbs_per_100g * $multiplier, 1),
                    'fat_per_100g' => $foodData->fat_per_100g,
                    'total_fat' => round($foodData->fat_per_100g * $multiplier, 1),
                    'found_in_db' => true,
                ];
            } else {
                // اگر در دیتابیس نبود
                $enriched[] = [
                    'food' => $foodName,
                    'amount' => $amount,
                    'found_in_db' => false,
                    'message' => 'اطلاعات کالری این غذا در دیتابیس موجود نیست',
                ];
            }
        }

        return $enriched;
    }

    private function convertToGrams(string $amount): float
    {
        $amount = strtolower(trim($amount));

        // استخراج عدد
        preg_match('/[\d.]+/', $amount, $matches);
        $number = isset($matches[0]) ? (float)$matches[0] : 1;

        // تبدیل واحدها
        if (preg_match('/kg|کیلو|کیلوگرم/', $amount)) {
            return $number * 1000;
        }
        if (preg_match('/g|گرم/', $amount)) {
            return $number;
        }
        if (preg_match('/cup|فنجان|لیوان/', $amount)) {
            return $number * 240; // یک لیوان تقریبا 240 گرم
        }
        if (preg_match('/glass|لیوان/', $amount)) {
            return $number * 250;
        }
        if (preg_match('/tablespoon|قاشق غذاخوری/', $amount)) {
            return $number * 15;
        }
        if (preg_match('/teaspoon|قاشق چایخوری/', $amount)) {
            return $number * 5;
        }
        if (preg_match('/piece|عدد|دونه/', $amount)) {
            return $number * 50; // فرض پیش‌فرض برای یک عدد
        }
        if (preg_match('/slice|برش/', $amount)) {
            return $number * 30;
        }
        if (preg_match('/bowl|کاسه/', $amount)) {
            return $number * 200;
        }
        if (preg_match('/plate|بشقاب/', $amount)) {
            return $number * 300;
        }

        // پیش‌فرض: 100 گرم
        return $number * 100;
    }

    public function health()
    {
        try {
            $response = Http::timeout(5)->post($this->ollamaUrl, [
                'model' => $this->ollamaModel,
                'prompt' => 'test',
                'stream' => false,
            ]);

            $dbConnected = false;
            $foodsCount = 0;

            try {
                $foodsCount = DB::table('foods')->count();
                $dbConnected = true;
            } catch (\Exception $e) {
                Log::error('Database connection error', ['message' => $e->getMessage()]);
            }

            return response()->json([
                'success' => $response->successful() && $dbConnected,
                'ollama' => [
                    'url' => $this->ollamaUrl,
                    'model' => $this->ollamaModel,
                    'status' => $response->status(),
                    'connected' => $response->successful(),
                ],
                'database' => [
                    'connected' => $dbConnected,
                    'foods_count' => $foodsCount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function searchFood(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2',
        ]);

        $query = $validated['query'];

        $foods = DB::table('foods')
            ->where('name', 'LIKE', "%{$query}%")
            ->orWhere('name_en', 'LIKE', "%{$query}%")
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $foods,
        ]);
    }
}
