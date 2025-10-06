<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Chat
 * 
 * @property int $id
 * @property int|null $user_id
 * @property int|null $doctor_id
 * @property int|null $status
 * @property int|null $allowed_message_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class Chat extends Model
{
	protected $table = 'chat';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'user_id' => 'int',
		'doctor_id' => 'int',
		'status' => 'int',
		'allowed_message_count' => 'int'
	];

	protected $fillable = [
		'user_id',
		'doctor_id',
		'status',
		'allowed_message_count'
	];
}
