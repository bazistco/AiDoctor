<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class DoctorComments extends Model
{
    protected $table = 'doctor_comments';
    protected $fillable = ['user_id','order_id','type_id','doctor_id','rate','status','text','created_at','updated_at'];
}
