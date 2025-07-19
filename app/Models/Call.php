<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Call extends Model
{
    protected $table = 'call';
    protected $fillable = ['user_id','doctor_id','duration','status','created_at','updated_at'];
}
