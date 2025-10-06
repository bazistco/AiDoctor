<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Question
 * 
 * @property int $id
 * @property int|null $question_type_id
 * @property int|null $illness_id
 * @property string|null $text
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Collection|Answer[] $answers
 *
 * @package App\Models
 */
class Question extends Model
{
	protected $table = 'questions';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'question_type_id' => 'int',
		'illness_id' => 'int'
	];

	protected $fillable = [
		'question_type_id',
		'illness_id',
		'text'
	];

	public function answers()
	{
		return $this->hasMany(Answer::class);
	}
}
