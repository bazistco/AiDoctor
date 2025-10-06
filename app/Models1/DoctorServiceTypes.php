<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class DoctorServiceTypes extends Model
{
    protected $table = 'doctor_service_types';
    protected $fillable = ['doctor_id', 'type_id','active','created_at','updated_at'];
}
