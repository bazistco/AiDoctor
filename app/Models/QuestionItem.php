<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class QuestionItem
 * 
 * @property int $id
 * @property int|null $question_id
 * @property string|null $text
 * @property int|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class QuestionItem extends Model
{
	protected $table = 'question_items';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'question_id' => 'int',
		'value' => 'int'
	];

	protected $fillable = [
		'question_id',
		'text',
		'value'
	];
}
