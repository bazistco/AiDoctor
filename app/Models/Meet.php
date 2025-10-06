<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Meet
 * 
 * @property int $id
 *
 * @package App\Models
 */
class Meet extends Model
{
	protected $table = 'meet';
	protected $primaryKey = 'id DESC';
	public $incrementing = false;
	public $timestamps = false;

	protected $casts = [
		'id' => 'int'
	];

	protected $fillable = [
		'id'
	];
}
