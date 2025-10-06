<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class DoctorPricesInfo
 * 
 * @property int $id
 * @property int|null $user_id
 * @property int|null $type_id
 * @property string|null $price_details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class DoctorPricesInfo extends Model
{
	protected $table = 'doctor_prices_info';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'user_id' => 'int',
		'type_id' => 'int'
	];

	protected $fillable = [
		'user_id',
		'type_id',
		'price_details'
	];
}
