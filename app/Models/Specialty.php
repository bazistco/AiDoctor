<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Specialty
 * 
 * @property int $id
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class Specialty extends Model
{
	protected $table = 'specialties';
	protected $primaryKey = 'id DESC';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int'
	];

	protected $fillable = [
		'id',
		'title'
	];
}
