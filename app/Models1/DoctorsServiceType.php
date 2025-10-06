<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DoctorsServiceType
 *
 * @property int $id
 * @property int|null $doctor_id
 * @property int|null $type_id
 * @property int|null $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class DoctorsServiceType extends Model
{
	protected $table = 'doctors_service_types';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'doctor_id' => 'int',
		'type_id' => 'int',
		'active' => 'int'
	];

	protected $fillable = [
		'doctor_id',
		'type_id',
		'active'
	];
}
