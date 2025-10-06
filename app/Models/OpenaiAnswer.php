<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class OpenaiAnswer
 * 
 * @property int $id
 * @property int|null $form_id
 * @property string|null $ai_answers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @package App\Models
 */
class OpenaiAnswer extends Model
{
	protected $table = 'openai_answers';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'form_id' => 'int'
	];

	protected $fillable = [
		'form_id',
		'ai_answers'
	];
}
