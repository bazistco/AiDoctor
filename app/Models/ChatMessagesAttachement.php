<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ChatMessagesAttachement
 * 
 * @property int $id
 * @property int|null $message_id
 * @property string|null $content_url
 * @property int|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class ChatMessagesAttachement extends Model
{
	protected $table = 'chat_messages_attachements';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'message_id' => 'int',
		'status' => 'int'
	];

	protected $fillable = [
		'message_id',
		'content_url',
		'status'
	];
}
