<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class UsersReserf
 *
 * @property int $id
 * @property int|null $doctor_id
 * @property int|null $user_id
 * @property int|null $status
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $time
 * @property int|null $reserve_type_id
 * @property int|null $reserve_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class UsersReserf extends Model
{
	protected $table = 'users_reserves';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'doctor_id' => 'int',
		'user_id' => 'int',
		'status' => 'int',
		'start_date' => 'datetime',
		'end_date' => 'datetime',
		'time' => 'datetime',
		'reserve_type_id' => 'int',
		'reserve_id' => 'int'
	];

	protected $fillable = [
		'doctor_id',
		'user_id',
		'status',
		'start_date',
		'end_date',
		'time',
		'reserve_type_id',
		'reserve_id'
	];
}
