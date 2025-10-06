<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Transaction
 *
 * @property int $id
 * @property int|null $source_user_id
 * @property int|null $des_user_id
 * @property int|null $payment_id
 * @property int|null $reason_id
 * @property float|null $amount
 * @property int|null $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class Transaction extends Model
{
	protected $table = 'transactions';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'source_user_id' => 'int',
		'des_user_id' => 'int',
		'payment_id' => 'int',
		'reason_id' => 'int',
		'amount' => 'float',
		'type' => 'int'
	];

	protected $fillable = [
		'source_user_id',
		'des_user_id',
		'payment_id',
		'reason_id',
		'amount',
		'type'
	];
}
