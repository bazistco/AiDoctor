<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class Payments extends Model
{
    protected $table = 'payments';
    protected $fillable = ['order_id','amount','status','pay_type','pay_callback','ref_num','trace_num','has_transaction','description','created_at','updated_at'];
    protected $casts=['pay_callback'=>'array'];
}
