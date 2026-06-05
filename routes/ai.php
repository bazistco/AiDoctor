<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('predict',function (Request $request) {
    try {
        $symptoms=translateExample($request->question);
        $predictions = predict_illness($symptoms);
        return response()->json([$predictions]);
    }catch (\Exception $e){
        return response()->json([$e->getMessage()]);
    }

});
Route::post('v2/predict',function (Request $request) {
    try {
        $illnesses=[];
        $symptoms=translateExample($request->question);
        sleep(1);
        $predictions = predict_illness($symptoms);
        foreach ($predictions as $prediction) {
            sleep(1);
            $illnesses[]=translateExample($prediction,'en','fa');
        }
        return response()->json([$illnesses]);
    }catch (\Exception $e){
        return response()->json([$e->getMessage()]);
    }

});

Route::get('test',function (Request $request) {dd('hi');});
