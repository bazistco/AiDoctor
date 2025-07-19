<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceTypes extends Model
{
    protected $table = 'service_types';
    protected $fillable = ['type_id','parent_id','created_at','updated_at'];
}
