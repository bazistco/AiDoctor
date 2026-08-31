<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DoctorPanelSubscriptionController extends Controller
{
    public function getPlans()
    {
        $plans = DB::table('doctor_plans')->where('status', 1)->orderBy('tier_level', 'asc')->get();
        return response()->json(['data' => $plans]);
    }

    public function getMyPlan()
    {
        $doctorId = auth()->id();

        // دریافت آخرین پلن فعال پزشک به همراه ضریب (multiplier)
        $subscription = DB::table('doctor_subscriptions')
            ->join('doctor_plans', 'doctor_subscriptions.plan_id', '=', 'doctor_plans.id')
            ->where('doctor_subscriptions.doctor_id', $doctorId)
            ->where('doctor_subscriptions.status', 1)
            ->where('doctor_subscriptions.expires_at', '>', Carbon::now())
            ->select(
                'doctor_subscriptions.*',
                'doctor_plans.name as plan_name',
                'doctor_plans.tier_level',
                'doctor_plans.description',
                'doctor_plans.multiplier' // <- این فیلد اضافه شد
            )
            ->orderBy('doctor_subscriptions.id', 'desc')
            ->first();

        return response()->json(['data' => $subscription]);
    }

    public function subscribeToPlan(Request $request)
    {
        $request->validate(['plan_id' => 'required|integer']);
        $doctorId = auth()->id();

        // دریافت اطلاعات پلن درخواستی
        $plan = DB::table('doctor_plans')->where('id', $request->plan_id)->where('status', 1)->first();
        if (!$plan) {
            return response()->json(['message' => 'پلن نامعتبر است.'], 404);
        }

        // TODO: لاجیک کسر از کیف پول را اینجا اعمال کنید...

        $expiresAt = Carbon::now()->addDays($plan->duration_days ?? 30);

        // داده‌هایی که باید در دیتابیس قرار بگیرند
        $subscriptionData = [
            'plan_id'       => $plan->id,
            'paid_price'    => $plan->price,
            'duration_days' => $plan->duration_days ?? 30,
            'status'        => 1,
            'starts_at'     => Carbon::now(),
            'expires_at'    => $expiresAt,
            'updated_at'    => Carbon::now(),
        ];

        // بررسی وجود اشتراک قبلی به دلیل وجود Unique Constraint روی doctor_id
        $existingSub = DB::table('doctor_subscriptions')->where('doctor_id', $doctorId)->first();

        if ($existingSub) {
            // اگر کاربر از قبل رکورد دارد، صرفاً همان رکورد را آپدیت (Over-write) می‌کنیم
            DB::table('doctor_subscriptions')
                ->where('doctor_id', $doctorId)
                ->update($subscriptionData);

            $subId = $existingSub->id;
        } else {
            // اگر کاربر هیچ‌گاه اشتراکی نداشته، رکورد جدید درج می‌کنیم
            $subscriptionData['doctor_id']  = $doctorId;
            $subscriptionData['created_at'] = Carbon::now();
            $subId = DB::table('doctor_subscriptions')->insertGetId($subscriptionData);
        }

        return response()->json([
            'message' => 'ارتقای پلن با موفقیت انجام شد.',
            'subscription_id' => $subId
        ]);
    }


}
