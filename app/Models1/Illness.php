<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Illness
 *
 * @property int $id
 * @property string|null $title
 * @property int|null $active
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $url_slug
 * @property string|null $image_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class Illness extends Model
{
	protected $table = 'illnesses';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'active' => 'int'
	];

	protected $fillable = [
		'title',
		'active',
		'description',
		'short_description',
		'url_slug',
		'image_url'
	];
}
