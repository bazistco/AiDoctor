<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorPricesInfo extends Model
{
    protected $table = 'doctor_prices_info';
    protected $fillable = ['user_id','type_id','price_details','created_at','updated_at'];
    protected $casts=['details'=>'array'];
}
