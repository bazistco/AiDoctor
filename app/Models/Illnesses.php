<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Illnesses extends Model
{
    protected $table = 'illnesses';
    protected $fillable = ['title','active','description','short_description','url_slug','image_url','created_at','updated_at'];

}
