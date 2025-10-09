<?php

use App\Models1\Cache;
use App\Models1\Polygon;
use App\Models1\PolygonDriver;
use Carbon\Carbon;
use Hekmatinasser\Verta\Verta;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Response;
use Stichoza\GoogleTranslate\GoogleTranslate;

function developerId()
{
    return 60000;
}

function toast($errors = ''){
    $html = '';
    if(session()->has(['toast']) || $errors) {
        $type = session()->has(['toast']) && session('toast')['result'] == 1 ? "text-bg-primary" : "text-bg-danger";
        $message = session()->has(['toast']) && session('toast')['message'] ? session('toast')['message'] : $errors->first();
        if($message) {
            $html .= '<div class="toast align-items-center show ' . $type . ' border-0" role="alert" aria-live="assertive" aria-atomic="true">';
            $html .= '<div class="d-flex">';
            $html .= '<div class="toast-body">';
            $html .= $message;
            $html .= '</div>';
            $html .= '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close" ></button>';
            $html .= '</div>';
            $html .= '</div>';
        }
    }
    echo $html;
}

function offlineMessage(){
    $html = '<div class="toast align-items-center show text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" wire:offline>';
    $html .= '<div class="d-flex">';
    $html .= '<div class="toast-body">';
    $html .= '<i class=\'bx bx-wifi-off\'></i> اتصال شما به اینترنت قطع می باشد';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    echo $html;
}
function sendToast($result, $message){
    session()->flash('toast',['result' => $result,'message' => $message]);
}

function breadCrumb($items = []){
    $html = '';
    $html = '<div class="cr-breadcrumb"><nav><ul>';
    $html .= '<li><a href="'.'#'.'">داشبورد</a></li>';
    $i=1;
    foreach ($items as $item){
        $html .= '<li>';
        if($i != count($items)) $html .= '<a href="'.route($item[1]).'">';
        $html .= $item[0];
        if($i != count($items)) $html .= '</a>';
        $html .= '</li>';
        $i+=1;
    }
    $html .= '</ul></nav></div>';
    echo $html;
}


function isMob($mob){
    return (strlen($mob) == 11 && is_numeric($mob) && substr($mob, 0, 2) == "09");
}

function isEmail($email){
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function spinner(){
    echo <<<HTML
    <div class="cr-table-spinner"><div class="spinner-border spinner-border-sm" role="status"></div></div>
    HTML;

}

function headTitle($data = []){
    return implode(' > ',$data);
}

function strRandom($length = 16)
{
    $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    return substr(str_shuffle(str_repeat($pool, 5)), 0, $length);
}

function weightFormat($number = 0){
    $text = '';
    if ($number >= 1) {
        $kilos = floor($number);
        $grams = ($number - $kilos) * 1000;
        $text .= number_format($kilos) . " کیلو ";

        if ($grams > 0) {
            $text .= 'و '.number_format($grams) . " گرم";
        }
    } else {
        $grams = $number * 1000;

        if ($grams > 0) {
            $text .= number_format($grams) . " گرم";
        }
    }
    return $text;

}

function tomanFormat($amount)
{
    return number_format(floor($amount)).' تومان';
}



/*function button($textButton = 'ذخیره',$icon = 'bx bx-plus', $wire = '',$class = '')
{
    echo <<<HTML
        <button $wire class="$class" wire:ignore.self wire:loading.attr="disabled"><span wire:loading.class="cr-hidden">$textButton</span>
        <i class="$icon" wire:loading.class="cr-hidden"></i>
        <span class="cr-hidden" wire:loading.class.remove="cr-hidden"><div class="cr-spinner"><div class="spinner-border spinner-border-sm" role="status"></div></div></span>
        </button>
        HTML;
}*/

function button($textButton = 'ذخیره', $icon = 'bx bx-plus', $action = '', $class = '')
{

    // استخراج action name از wire:click (برای wire:target)
    $target = '';
    if (preg_match('/wire:click(?:\\.prevent)?="([^"]+)"/', $action, $matches)) {
        $target = $matches[1];
    }

    echo <<<HTML
        <button $action class="$class" wire:ignore.self wire:loading.attr="disabled" wire:target="$target">
            <span wire:loading.remove wire:target="$target">
                $textButton
                <i class="$icon"></i>
            </span>
            <span wire:loading wire:target="$target">
                <div class="spinner-border spinner-border-sm" role="status"></div>
            </span>
        </button>
    HTML;
}
function load_button($textButton = 'ذخیره',$icon = 'bx bx-plus', $wire = '',$class = '',$property = '')
{
    echo <<<HTML
        <button $wire class="$class" wire:ignore.self wire:loading.attr="disabled" $property><span wire:loading.class="cr-hidden">$textButton</span>
        <i class="$icon" wire:loading.class="cr-hidden"></i>
        <span class="cr-hidden" wire:loading.class.remove="cr-hidden"><div class="cr-spinner"><div class="spinner-border spinner-border-sm" role="status"></div></div></span>
        </button>
        HTML;
}
if (! function_exists('convertDigits')) {
    function convertDigits($string)
    {
        if($string === null)
            return null;
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

        $num = range(0, 9);
        $convertedPersianNums = str_replace($persian, $num, $string);
        $englishNumbersOnly = str_replace($arabic, $num, $convertedPersianNums);

        return $englishNumbersOnly;
    }
}

function toGregorian($date = '',$separator = '-',$separateTo = '-', $showHour = true)
{
    if(empty($date))
        return '';
    $dateTime = explode(' ',$date);
    if(count($dateTime) > 1) {
        list($date, $time) = $dateTime;
        $time = ' '.$time;
    }
    else{
        $date = $dateTime[0];
        $time = '';
    }
    $exp = explode($separator, convertDigits($date));
    $date = implode($separateTo, Verta::jalaliToGregorian($exp[0],$exp[1],$exp[2]));
    $dateExp = explode($separateTo,$date);
    $dateExp[1] = strlen($dateExp[1]) === 1 ? '0'.$dateExp[1] : $dateExp[1];
    $dateExp[2] = strlen($dateExp[2]) === 1 ? '0'.$dateExp[2] : $dateExp[2];
    $date = implode($separateTo,$dateExp);
    if($showHour == true)
        return $date.$time;
    else
        return $date;

}

function bazistDistrict($point)
{
    $polygon = Polygon::all();
    $district = [];

    foreach($polygon as $poly) {
        $vs = json_decode($poly->polygon);
        $inside = false;
        $x = $point[0]; // lng
        $y = $point[1]; // lat

        for ($i = 0, $j = count($vs) - 1; $i < count($vs); $j = $i++) {
            $xi = $vs[$i][0];
            $yi = $vs[$i][1];
            $xj = $vs[$j][0];
            $yj = $vs[$j][1];

            $intersect = (($yi > $y) != ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
            if ($intersect) $inside = !$inside;
        }

        if ($inside) {
            array_push($district, $poly->region);
        }
    }
    return implode(' ,', $district);
}

function isLocationInsidePolygon($driverId,$point)
{
    $polygon = PolygonDriver::where('user_id', $driverId)->with('polygon')->get();

    foreach($polygon as $poly) {
        $vs = json_decode($poly->polygon->polygon);
        $inside = false;
        $x = $point[0];
        $y = $point[1];

        for ($i = 0, $j = count($vs) - 1; $i < count($vs); $j = $i++) {
            $xi = $vs[$i][0];
            $yi = $vs[$i][1];
            $xj = $vs[$j][0];
            $yj = $vs[$j][1];

            $intersect = (($yi > $y) != ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
            if ($intersect) $inside = !$inside;
        }

        if ($inside) {
            return $inside;
        }
    }
}

function sendJson($status = 'success', $message = '', $data = null,$state = null){
    if($state == null){
        //$state = $result == 1 ? 200 : 400;
        $state = 200;
    }
    return response()->json([
        'status'  => $status,
        'message' => $message,
        'data'    => $data,
    ], $state)->header('Content-type', 'application/json');
    die;
}


function wageToman()
{
    return 700;
}

function wageRial()
{
    return wageToman()*10;
}

function userRewardToman()
{
    return Cache::get('userReward');
}

function userRewardRial()
{
    return userRewardToman()*10;
}

function referrerRewardToman()
{
    return 30000;
}

function referrerRewardRial()
{
    return referrerRewardToman()*10;
}


function referrerRewardAbove50KiloToman()
{
    return 20000;
}

function referrerRewardAbove50KiloRial()
{
    return referrerRewardAbove50KiloToman()*10;
}

function minWithdrawCardToCardToman()
{
    return 10000;
}

function minWithdrawAapToman()
{
    return 10000;
}

function isNationalCode($meli)
{

    $cDigitLast = substr($meli , strlen($meli)-1);
    $fMeli = strval(intval($meli));

    if((str_split($fMeli))[0] == "0" && !(8 <= strlen($fMeli)  && strlen($fMeli) < 10)) return false;

    $nineLeftDigits = substr($meli , 0 , strlen($meli) - 1);

    $positionNumber = 10;
    $result = 0;

    foreach(str_split($nineLeftDigits) as $chr){
        $digit = intval($chr);
        $result += $digit * $positionNumber;
        $positionNumber--;
    }

    $remain = $result % 11;

    $controllerNumber = $remain;

    if(2 <= $remain){
        $controllerNumber = 11-$remain;
    }

    return $cDigitLast == $controllerNumber;

}

function calculateMinutesPassed($inputDate)
{
    $inputDate = Carbon::parse($inputDate)->format('Y-m-d H:i');
    $parsedDate = Carbon::createFromFormat('Y-m-d H:i', $inputDate);
    $now = Carbon::now();
    $minutesPassed = $parsedDate->diffInMinutes($now);
    return $minutesPassed;
}

function sendMetaJson(bool $status, string $msg, array|object $details = [], int $statusCode = 200): JsonResponse{
    return Response::json([
        'status'  => $status,
        'msg'     => $msg,
        'code'    => $statusCode,
        'details' => $details
    ],
    $statusCode
    );
}

function taxCalculate($amount,$taxRate)
{
    return $amount * ($taxRate / 100);
}

function randomIranianMobile($withZero = true) {
    $prefixes = [
        '0910', '0911', '0912', '0913', '0914', '0915', '0916', '0917', '0918', '0919',
        '0920', '0921', '0922', '0923',
        '0930', '0933', '0935', '0936', '0937', '0938', '0939'
    ];
    $randomPrefix = $prefixes[array_rand($prefixes)];
    $randomNumber = str_pad(mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
    $fullNumber = $randomPrefix . $randomNumber;
    if (!$withZero) {
        $fullNumber = ltrim($fullNumber, '0');
    }
    return $fullNumber;
}

function normalizeToRange($value, $min, $max, $targetRange = 100){
    return (($value - $min) / ($max - $min)) * $targetRange;
}
function predict_illness($question)
{
    set_time_limit(300);
    $question = str_replace(["\r", "\n"], '', $question);
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'http://localhost:11434/api/chat',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>'{
  "model": "hf.co/mradermacher/Llama-chatDoctor-i1-GGUF:Q4_K_M",
  "messages": [
    {
      "role": "user",
      "content": "'.$question.' Predict possible diseases ."
    }
  ],
  "stream": false,
  "format": {
    "type": "object",
    "properties": {
      "predicted_diseases": {
        "type": "array",
        "items": { "type": "string" },
        "description": "List of predicted diseases based on symptoms"
      }
    },
    "required": ["predicted_diseases"]
  }
}',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);
    curl_close($curl);
    $response=json_decode($response);
    $illnesses=json_decode($response->message->content);
    return $illnesses->predicted_diseases ?? [] ;
}
 function translateExample($text,$s='fa',$d='en')
{
    $tr = new GoogleTranslate($d); // زبان مقصد
    $tr->setSource($s);          // تشخیص خودکار زبان
    $result = $tr->translate($text);
    return $result; // "Hello, how are you?"
}


