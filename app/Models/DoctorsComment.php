<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DoctorsComment
 * 
 * @property int $id
 * @property int|null $order_id
 * @property int|null $type_id
 * @property string|null $rate
 * @property int|null $user_id
 * @property int|null $doctor_id
 * @property int|null $status
 * @property string|null $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class DoctorsComment extends Model
{
	protected $table = 'doctors_comments';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'order_id' => 'int',
		'type_id' => 'int',
		'user_id' => 'int',
		'doctor_id' => 'int',
		'status' => 'int'
	];

	protected $fillable = [
		'order_id',
		'type_id',
		'rate',
		'user_id',
		'doctor_id',
		'status',
		'text'
	];
}
