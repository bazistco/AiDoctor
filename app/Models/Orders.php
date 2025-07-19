<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Orders extends Model
{
    protected $table = 'orders';
    protected $fillable = ['user_id','service_id','sub_service_id','payment_id','service_reserve_type_id','payment_id','service_reserve_id','raw_amount','discount_amount','discount_id','final_amount','created_at','updated_at'];
}
