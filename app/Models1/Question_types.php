<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class Question_types extends Model
{
    protected $table = 'question_types';
    protected $fillable = ['title','created_at','updated_at'];
}
