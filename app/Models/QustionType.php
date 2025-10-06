<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class QustionType
 * 
 * @property int $id
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class QustionType extends Model
{
	protected $table = 'qustion_types';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int'
	];

	protected $fillable = [
		'title'
	];
}
