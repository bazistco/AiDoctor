<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Class DoctorInfo
 * 
 * @property int $user_id
 * @property int|null $nezam_code
 * @property int|null $has_online_prescription
 * @property string|null $insurances
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $image_url
 * @property string|null $location_info
 * @property string|null $url_slug
 *
 * @package App\Models
 */
class DoctorInfo extends Model
{
	protected $table = 'doctor_info';
	protected $primaryKey = 'user_id';
	public $incrementing = false;
	public $timestamps = false;

	protected $casts = [
		'user_id' => 'int',
		'nezam_code' => 'int',
		'has_online_prescription' => 'int'
	];

	protected $fillable = [
		'nezam_code',
		'has_online_prescription',
		'insurances',
		'description',
		'short_description',
		'image_url',
		'location_info',
		'url_slug'
	];
}
