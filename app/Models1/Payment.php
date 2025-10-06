<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Payment
 *
 * @property int $id
 * @property float|null $amount
 * @property int|null $order_id
 * @property int|null $status
 * @property int|null $pay_type
 * @property string|null $pay_callback
 * @property string|null $ref_nuf
 * @property string|null $trace_num
 * @property int|null $has_transaction
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class Payment extends Model
{
	protected $table = 'payments';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'amount' => 'float',
		'order_id' => 'int',
		'status' => 'int',
		'pay_type' => 'int',
		'has_transaction' => 'int'
	];

	protected $fillable = [
		'amount',
		'order_id',
		'status',
		'pay_type',
		'pay_callback',
		'ref_nuf',
		'trace_num',
		'has_transaction',
		'description'
	];
}
