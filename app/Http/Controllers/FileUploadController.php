<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // حداکثر 10MB
        ]);

        try {
            $file = $request->file('file');

            // ساخت نام یونیک برای فایل
            $fileName = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();

            // ذخیره فایل در storage/app/public/uploads
            $path = $file->storeAs('assets/img/fit', $fileName, 'public');

            // ساخت URL کامل فایل
            $url = asset('storage/' . $path);

            return response()->json([
                'success' => true,
                'message' => 'فایل با موفقیت آپلود شد',
                'data' => [
                    'file_name' => $fileName,
                    'file_path' => $path,
                    'url' => $url
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در آپلود فایل',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
