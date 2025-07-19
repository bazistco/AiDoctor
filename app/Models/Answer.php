<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Answer extends Model
{
    protected $table = 'answers';
    protected $fillable = ['form_id', 'question_id', 'numeric_answer','text_answer','created_at','updated_at'];
}
