<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Order
 * 
 * @property int $id
 * @property int|null $user_id
 * @property int|null $service_id
 * @property int|null $sub_service_id
 * @property int|null $payment_id
 * @property int|null $service_reserve_type_id
 * @property int|null $service_reserve_id
 * @property float|null $raw_amount
 * @property float|null $discount_amount
 * @property int|null $discount_id
 * @property float|null $final_amount
 * @property int|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class Order extends Model
{
	protected $table = 'orders';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'user_id' => 'int',
		'service_id' => 'int',
		'sub_service_id' => 'int',
		'payment_id' => 'int',
		'service_reserve_type_id' => 'int',
		'service_reserve_id' => 'int',
		'raw_amount' => 'float',
		'discount_amount' => 'float',
		'discount_id' => 'int',
		'final_amount' => 'float',
		'status' => 'int'
	];

	protected $fillable = [
		'user_id',
		'service_id',
		'sub_service_id',
		'payment_id',
		'service_reserve_type_id',
		'service_reserve_id',
		'raw_amount',
		'discount_amount',
		'discount_id',
		'final_amount',
		'status'
	];
}
