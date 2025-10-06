<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Answer
 * 
 * @property int $id
 * @property int|null $form_id
 * @property int|null $question_id
 * @property int|null $numeric_answer
 * @property string|null $text_answer
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property UsersQuestionForm|null $users_question_form
 * @property Question|null $question
 *
 * @package App\Models
 */
class Answer extends Model
{
	protected $table = 'answers';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'form_id' => 'int',
		'question_id' => 'int',
		'numeric_answer' => 'int'
	];

	protected $fillable = [
		'form_id',
		'question_id',
		'numeric_answer',
		'text_answer'
	];

	public function users_question_form()
	{
		return $this->belongsTo(UsersQuestionForm::class, 'form_id');
	}

	public function question()
	{
		return $this->belongsTo(Question::class);
	}
}
