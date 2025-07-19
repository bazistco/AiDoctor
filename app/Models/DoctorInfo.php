<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DoctorInfo extends Model
{
    protected $table = 'doctor_info';
    protected $fillable = ['user_id', 'nezam_code', 'has_online_prescription', 'insurances','description','short_description','image_url','location_info','url_slug','created_at','updated_at'];
    protected $casts = ['insurances' => 'array','location_info' => 'array'];
}
