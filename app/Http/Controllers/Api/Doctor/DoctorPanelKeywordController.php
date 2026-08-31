<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorPanelKeywordController extends Controller
{
    public function deleteKeyword($id)
    {
        $doctorId = auth()->id(); // با فرض استفاده از Sanctum

        $deleted = DB::table('doctor_keyword_subscriptions')
            ->where('id', $id)
            ->where('doctor_id', $doctorId)
            ->delete();

        if ($deleted) {
            return response()->json(['message' => 'کلمه کلیدی با موفقیت حذف شد.']);
        }

        return response()->json(['message' => 'کلمه یافت نشد یا دسترسی ندارید.'], 404);
    }

    /**
     * ۱. دریافت لیست تمام کلمات کلیدی موجود برای خرید
     */
    public function getAvailableKeywords(Request $request)
    {
        $search = $request->input('search');
        $doctorId = $request->user()->id;

        $query = DB::table('keywords');

        if (!empty($search)) {
            $query->where('word', 'LIKE', '%' . $search . '%');
        }

        // فقط کلماتی را بیاور که پزشک در حال حاضر اشتراک فعال و منقضی‌نشده برای آن‌ها ندارد
        $query->whereNotIn('id', function($q) use ($doctorId) {
            $q->select('keyword_id')
                ->from('doctor_keyword_subscriptions')
                ->where('doctor_id', $doctorId)
                ->where('expires_at', '>', now()); // فقط رکوردهای منقضی نشده لحاظ شوند
        });

        $keywords = $query->orderBy('word', 'asc')->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => $keywords
        ]);
    }

    public function getMyKeywords(Request $request)
    {
        $doctorId = $request->user()->id;

        // ابتدا پلن فعال پزشک را برای استخراج ضریب پیدا می‌کنیم
        $activePlan = DB::table('doctor_subscriptions as ds')
            ->join('doctor_plans as dp', 'ds.plan_id', '=', 'dp.id')
            ->where('ds.doctor_id', $doctorId)
            ->where('ds.status', 1)
            ->where('ds.expires_at', '>', Carbon::now())
            ->select('dp.multiplier')
            ->first();

        $multiplier = $activePlan ? $activePlan->multiplier : 1.00;

        $myKeywords = DB::table('doctor_keyword_subscriptions as dks')
            ->join('keywords as k', 'dks.keyword_id', '=', 'k.id')
            ->where('dks.doctor_id', $doctorId)
            ->select(
                'dks.id',
                'dks.keyword_id',
                'k.word as keyword_name',
                'k.base_click_tariff',       // برای محاسبه نیاز داریم
                'k.base_impression_tariff',  // برای محاسبه نیاز داریم
                DB::raw("IF(dks.is_active = 1, 'active', 'paused') as status"),
                'dks.tier_level',
                'dks.expires_at',
                'dks.created_at'
            )
            ->orderBy('dks.created_at', 'desc')
            ->get();

        // اعمال ضریب (multiplier) روی رکوردهای استخراج شده
        $myKeywords->transform(function ($item) use ($multiplier) {
            $item->final_click_tariff = $item->base_click_tariff * $multiplier;
            $item->final_impression_tariff = $item->base_impression_tariff * $multiplier;
            return $item;
        });

        return response()->json([
            'status' => 'success',
            'data' => $myKeywords
        ]);
    }

    /**
     * ۳. خرید / عضویت در یک کلمه کلیدی جدید (محاسبه سطح بر اساس پلن فعال)
     */
    public function subscribeToKeyword(Request $request)
    {
        $request->validate([
            'keyword_id' => 'required|exists:keywords,id',
            'duration_days' => 'required|integer|min:1'
        ]);

        $doctorId = $request->user()->id;
        $keywordId = $request->input('keyword_id');
        $durationDays = $request->input('duration_days');

        return DB::transaction(function () use ($doctorId, $keywordId, $durationDays) {

            // ۱. بررسی پلن فعال (سطح Tier دیگر از فرانت نمی‌آید، بلکه از پلن فعال گرفته می‌شود)
            $activePlanData = DB::table('doctor_subscriptions as ds')
                ->join('doctor_plans as dp', 'ds.plan_id', '=', 'dp.id')
                ->where('ds.doctor_id', $doctorId)
                ->where('ds.status', 1)
                ->where('ds.expires_at', '>', Carbon::now())
                ->select('dp.max_allowable_debt', 'dp.tier_level')
                ->first();

            if (!$activePlanData) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'برای خرید کلمه کلیدی، ابتدا باید یک پلن VIP فعال داشته باشید.'
                ], 403);
            }

            // ... لاجیک بررسی موجودی و سقف بدهی ...

            $existingSub = DB::table('doctor_keyword_subscriptions')
                ->where('doctor_id', $doctorId)
                ->where('keyword_id', $keywordId)
                ->where('expires_at', '>', Carbon::now())
                ->exists();

            if ($existingSub) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'شما هم‌اکنون اشتراک فعال برای این کلمه دارید.'
                ], 409);
            }

            // ۴. ثبت کلمه کلیدی جدید با سطح (Tier) استخراج شده از پلن کاربر
            $insertData = [
                'doctor_id'  => $doctorId,
                'keyword_id' => $keywordId,
                'tier_level' => $activePlanData->tier_level, // سطح خودکار اعمال شد
                'is_active'  => 1,
                'expires_at' => Carbon::now()->addDays($durationDays),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];

            $newSubscriptionId = DB::table('doctor_keyword_subscriptions')->insertGetId($insertData);
            $insertData['id'] = $newSubscriptionId;

            return response()->json([
                'status' => 'success',
                'message' => 'کلمه کلیدی با موفقیت خریداری شد.',
                'data' => array_merge($insertData, ['status' => 'active'])
            ], 201);
        });
    }

    /**
     * ۴. تغییر وضعیت کلمه کلیدی (توقف موقت / فعال‌سازی مجدد)
     */
    public function toggleKeywordStatus(Request $request, $id)
    {
        $doctorId = $request->user()->id;

        // پیدا کردن رکورد مربوطه که متعلق به همین پزشک است
        $subscription = DB::table('doctor_keyword_subscriptions')
            ->where('id', $id)
            ->where('doctor_id', $doctorId)
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'اشتراک یافت نشد یا شما دسترسی به آن ندارید.'
            ], 404);
        }

        // سوئیچ کردن وضعیت (اگر ۱ بود به ۰، و اگر ۰ بود به ۱ تغییر کند)
        $newIsActive = $subscription->is_active == 1 ? 0 : 1;
        $statusString = $newIsActive == 1 ? 'active' : 'paused';

        // به‌روزرسانی در دیتابیس
        DB::table('doctor_keyword_subscriptions')
            ->where('id', $id)
            ->update([
                'is_active' => $newIsActive,
                'updated_at' => now()
            ]);

        return response()->json([
            'status' => 'success',
            'message' => "وضعیت کلمه کلیدی به '$statusString' تغییر یافت.",
            'data' => [
                'id' => $id,
                'status' => $statusString,
                'is_active' => $newIsActive
            ]
        ]);
    }

    /**
     * ۵. دریافت ریز سابقه تراکنش‌های مصرفی
     */
    public function getConsumptionLogs(Request $request)
    {
        $doctorId = $request->user()->id;
        $keywordId = $request->input('keyword_id');

        $query = DB::table('keyword_consumption_logs as kcl')
            ->join('keywords as k', 'kcl.keyword_id', '=', 'k.id')
            ->where('kcl.doctor_id', $doctorId)
            ->select(
                'kcl.id',
                'k.word as keyword_name',
                'kcl.action_type',
                'kcl.cost',
                'kcl.ip_address',
                'kcl.created_at'
            )
            ->orderBy('kcl.created_at', 'desc');

        if (!empty($keywordId)) {
            $query->where('kcl.keyword_id', $keywordId);
        }

        $logs = $query->paginate(50);

        return response()->json([
            'status' => 'success',
            'data' => $logs
        ]);
    }

    /**
     * ۶. دریافت گزارش تجمیعی و نموداری
     * فرمول ریاضی: $$ Total Cost = \sum_{i=1}^{n} Cost_i $$
     */
    public function getDailyConsumptionChart(Request $request)
    {
        $doctorId = $request->user()->id;
        $keywordId = $request->input('keyword_id');

        $thirtyDaysAgo = now()->subDays(30)->startOfDay();

        $query = DB::table('keyword_consumption_logs')
            ->where('doctor_id', $doctorId)
            ->where('created_at', '>=', $thirtyDaysAgo);

        if (!empty($keywordId)) {
            $query->where('keyword_id', $keywordId);
        }

        $chartData = $query->select(
            DB::raw('DATE(created_at) as date'),
            'action_type',
            DB::raw('COUNT(id) as total_count'),
            DB::raw('SUM(cost) as total_cost')
        )
            ->groupBy(DB::raw('DATE(created_at)'), 'action_type')
            ->orderBy('date', 'asc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $chartData
        ]);
    }
}
