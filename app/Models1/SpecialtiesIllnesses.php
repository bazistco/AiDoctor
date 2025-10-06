<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class SpecialtiesIllnesses extends Model
{
    protected $table = 'specialties_illnesses';
    protected $fillable = ['speciality_id', 'illnesses_id'];
}
