<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class CallLog
 * 
 * @property int $id
 * @property int|null $call_id
 * @property string|null $detail
 * @property string|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class CallLog extends Model
{
	protected $table = 'call_log';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'call_id' => 'int'
	];

	protected $fillable = [
		'call_id',
		'detail'
	];
}
