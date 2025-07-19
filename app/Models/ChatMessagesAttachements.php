<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessagesAttachements extends Model
{
    protected $table = 'chat_messages_attachements';
    protected $fillable = ['chat_id','content_url','status','created_at','updated_at'];
}
