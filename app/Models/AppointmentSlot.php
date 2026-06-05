<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppointmentSlot extends Model
{
    protected $table = 'appointment_slots';

    protected $fillable = [
        'doctor_id',
        'slot_date',
        'start_time',
        'end_time',
        'status',
        'patient_id',
        'booking_time',
        'notes',
        'reserved_until',
    ];

    protected $casts = [
        'slot_date' => 'date',
        'booking_time' => 'datetime',
        'reserved_until' => 'datetime',
    ];

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}
