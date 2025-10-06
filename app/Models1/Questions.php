<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class Questions extends Model
{
    protected $table = 'questions';
    protected $fillable = ['question_type_id','illness_id','text','created_at','updated_at'];
}
