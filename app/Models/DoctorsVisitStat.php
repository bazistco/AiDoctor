<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class DoctorsVisitStat
 * 
 * @property int $user_id
 *
 * @package App\Models
 */
class DoctorsVisitStat extends Model
{
	protected $table = 'doctors_visit_stats';
	protected $primaryKey = 'user_id';
	public $incrementing = false;
	public $timestamps = false;

	protected $casts = [
		'user_id' => 'int'
	];
}
