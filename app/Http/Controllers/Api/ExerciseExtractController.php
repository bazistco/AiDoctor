<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExerciseExtractController extends Controller
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
            'text' => 'required|string|max:3000',
        ]);

        $text = $validated['text'];

        $prompt = <<<PROMPT
You are a strict workout data extraction engine.

Your task is to extract workout exercises and their amount from the given text.

Rules:
- Extract ONLY workout exercises.
- Ignore any non-exercise words.
- Convert written numbers to digits (e.g., "two" → 2).
- Exercise names must be singular (e.g., "squats" → "squat").
- Remove hyphens from exercise names (e.g., "push-ups" → "push up").
- If a number appears without a unit, assume "reps".
- If time is mentioned, keep the unit "minute(s)" or "second(s)".
- If an exercise has no amount, assume "1 rep".
- If the same exercise appears multiple times, combine them.

Allowed units:
reps, minute, minutes, second, seconds

Output requirements:
- Return ONLY valid JSON.
- Do NOT include explanations.
- Do NOT include markdown formatting.
- Do NOT include any text before or after the JSON.
- The response must start with { and end with }.

Output schema:

{
  "items": [
    {
      "exercise": "exercise name",
      "amount": "number + unit"
    }
  ]
}

Example:

Input:
I did 20 squats and 1 minute plank.

Output:
{"items":[{"exercise":"squat","amount":"20 reps"},{"exercise":"plank","amount":"1 minute"}]}

TEXT:
{$text}

Return only the JSON result.
PROMPT;


        try {

            $response = Http::timeout(60)->post($this->ollamaUrl, [
                'model' => $this->ollamaModel,
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.1,
                ],
            ]);

            if (!$response->successful()) {
                return response()->json(['success' => false], 500);
            }

            $data = $response->json();
            $raw = $data['response'] ?? '';

            if (preg_match('/\{[\s\S]*\}/', $raw, $matches)) {

                $parsed = json_decode($matches[0], true);

                if (isset($parsed['items'])) {

                    $enriched = $this->enrichExercises($parsed['items']);

                    return response()->json([
                        'success' => true,
                        'data' => [
                            'items' => $enriched,
                            'total_calories_burned' =>
                                array_sum(array_column($enriched, 'total_calories')),
                        ],
                        'raw_response' => $raw
                    ]);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Invalid format',
                'raw' => $raw
            ], 422);

        } catch (\Exception $e) {

            Log::error($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function enrichExercises(array $items): array
    {
        $result = [];

        foreach ($items as $item) {

            $name = $this->normalizeExerciseName($item['exercise']);
            $name = $this->mapExerciseSynonym($name);

            $amount = strtolower($item['amount']);

            $exercise = $this->findExercise($name);

            if (!$exercise) {
                continue;
            }

            preg_match('/[\d.]+/', $amount, $match);
            $number = isset($match[0]) ? (float)$match[0] : 1;

            $totalCalories = 0;

            if ($exercise->type === 'reps') {
                $totalCalories = $number * $exercise->calories_per_rep;
            }

            if ($exercise->type === 'time') {

                if (str_contains($amount, 'minute')) {
                    $totalCalories = $number * $exercise->calories_per_min;
                }

                if (str_contains($amount, 'second')) {
                    $totalCalories = ($number / 60) * $exercise->calories_per_min;
                }
            }

            $result[] = [
                'exercise' => $name,
                'exercise_fa' => $exercise->name,
                'exercise_en' => $exercise->name_en,
                'amount' => $amount,
                'muscle_group' => $exercise->muscle_group,
                'total_calories' => round($totalCalories, 2),
                'image_url'=> $exercise->image_url,
            ];
        }

        return $result;
    }

    private function normalizeExerciseName(string $name): string
    {
        $name = strtolower($name);

        $name = str_replace('-', ' ', $name);

        $name = preg_replace('/s$/', '', $name);

        $name = preg_replace('/\s+/', ' ', $name);

        return trim($name);
    }

    private function mapExerciseSynonym(string $name): string
    {
        $map = [

            'pushup' => 'push up',
            'press up' => 'push up',

            'situp' => 'sit up',

            'pullup' => 'pull up',
            'chinup' => 'chin up',

            'jumping jack' => 'jumping jack',

            'rope jump' => 'jump rope',

            'run' => 'running',
            'walk' => 'walking',
            'cycle' => 'cycling',

        ];

        return $map[$name] ?? $name;
    }

    private function findExercise(string $name)
    {
        $exercise = DB::table('exercises')
            ->whereRaw('LOWER(name_en) = ?', [$name])
            ->first();

        if ($exercise) {
            return $exercise;
        }

        $exercise = DB::table('exercises')
            ->whereRaw('LOWER(name_en) LIKE ?', ["%{$name}%"])
            ->first();

        if ($exercise) {
            return $exercise;
        }

        $exercise = DB::table('exercises')
            ->whereRaw('? LIKE CONCAT("%", LOWER(name_en), "%")', [$name])
            ->first();

        return $exercise;
    }
}
