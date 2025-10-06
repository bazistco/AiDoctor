<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class SpecialtiesIllness
 * 
 * @property int $id
 * @property int|null $speciality_id
 * @property int|null $illness_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class SpecialtiesIllness extends Model
{
	protected $table = 'specialties_illnesses';

	protected $casts = [
		'speciality_id' => 'int',
		'illness_id' => 'int'
	];

	protected $fillable = [
		'speciality_id',
		'illness_id'
	];
}
