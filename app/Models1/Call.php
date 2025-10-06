<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Call
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $doctor_id
 * @property int|null $duration
 * @property int|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class Call extends Model
{
	protected $table = 'call';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'user_id' => 'int',
		'doctor_id' => 'int',
		'duration' => 'int',
		'status' => 'int'
	];

	protected $fillable = [
		'user_id',
		'doctor_id',
		'duration',
		'status'
	];
}
