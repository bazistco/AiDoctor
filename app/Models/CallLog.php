<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallLog extends Model
{
    //
    protected $table = 'call_log';

    protected $fillable=['call_id','detail','created_at','updated_at'];
}
