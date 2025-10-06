<?php

namespace App\Models1;

use Illuminate\Database\Eloquent\Model;

class QuestionItems extends Model
{
    protected $table = 'question_items';
    protected $fillable = ['question_id','text','value','created_at','updated_at'];

}
