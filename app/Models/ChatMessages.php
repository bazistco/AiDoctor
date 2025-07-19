<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessages extends Model
{
    protected $table = 'chat_messages';
    protected $fillable = ['chat_id', 'text','status','created_at','updated_at'];
}
