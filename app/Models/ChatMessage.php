<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ChatMessage
 * 
 * @property int $id
 * @property int|null $chat_id
 * @property string|null $text
 * @property int|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $seen_at
 *
 * @package App\Models
 */
class ChatMessage extends Model
{
	protected $table = 'chat_messages';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'chat_id' => 'int',
		'status' => 'int',
		'seen_at' => 'datetime'
	];

	protected $fillable = [
		'chat_id',
		'text',
		'status',
		'seen_at'
	];
}
