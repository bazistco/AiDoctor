<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class AiAnswers extends Model
{
    //
    protected $table = 'openai_answers';
    protected $fillable = ['form_id','ai_answers','created_at','updated_at'];
}
