<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class ServiceType
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string|null $title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class ServiceType extends Model
{
	protected $table = 'service_types';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'parent_id' => 'int'
	];

	protected $fillable = [
		'parent_id',
		'title'
	];
}
