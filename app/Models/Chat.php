<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    protected $table = 'chat';
    protected $fillable = ['user_id','doctor_id','status','allowed_message_count','created_at','updated_at'];

}
