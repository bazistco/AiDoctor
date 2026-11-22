<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PillShareCodeService
{
    /**
     * تضمین وجود کد اختصاصی یکتا برای کاربر (حتی کاربران قدیمی)
     */
    public function ensureCodeForUser(int $userId): string
    {
        return DB::transaction(function () use ($userId) {
            $user = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$user) {
                throw new \RuntimeException('کاربر یافت نشد.');
            }

            if (!empty($user->pill_share_code)) {
                return (string) $user->pill_share_code;
            }

            do {
                // حروف خوانا (بدون 0, O, 1, I برای جلوگیری از خطای تایپی کاربران)
                $code = $this->generateReadableCode(8);
            } while (DB::table('users')->where('pill_share_code', $code)->exists());

            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'pill_share_code' => $code,
                    'updated_at' => now(),
                ]);

            return $code;
        });
    }

    private function generateReadableCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIndex = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $maxIndex)];
        }

        return $code;
    }
}
