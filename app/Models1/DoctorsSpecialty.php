<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DoctorsSpecialty
 *
 * @property int $id
 * @property int|null $doctor_id
 * @property int|null $specialty_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class DoctorsSpecialty extends Model
{
	protected $table = 'doctors_specialties';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'doctor_id' => 'int',
		'specialty_id' => 'int'
	];

	protected $fillable = [
		'doctor_id',
		'specialty_id'
	];
}
