<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class DoctorSpecialties extends Model
{
    protected $table = 'doctor_specialties';
    protected $fillable = ['doctor_id', 'type_id','active','created_at','updated_at'];

}
