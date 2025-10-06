<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class UsersQuestionForm
 * 
 * @property int $id
 * @property int|null $user_id
 * @property int|null $illness_id
 * @property int|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Collection|Answer[] $answers
 *
 * @package App\Models
 */
class UsersQuestionForm extends Model
{
	protected $table = 'users_question_forms';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'user_id' => 'int',
		'illness_id' => 'int',
		'status' => 'int'
	];

	protected $fillable = [
		'user_id',
		'illness_id',
		'status'
	];

	public function answers()
	{
		return $this->hasMany(Answer::class, 'form_id');
	}
}
