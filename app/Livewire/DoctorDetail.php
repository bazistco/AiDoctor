<?php

namespace App\Livewire;

use Livewire\Component;

class DoctorDetail extends Component
{
    public $doctorId;
    public $doctor;
    public $comments = [];
public function mount($doctorId=null)
{
    $this->doctorId = $doctorId;
    $this->loadDoctor();
}


protected function loadDoctor()
{
// Load the doctor. Adjust relations/fields to your DB
    $this->doctor = collect([]);


// fallback sample data so component still renders in dev
        $this->doctor = (object)[
            'id' => 0,
            'name' => 'دکتر نمونه',
            'specialty' => 'تخصص نمونه',
            'avatar' => '/images/default-doctor.png',
            'successful_visits' => 124,
            'avg_rating' => 4.6,
            'nursing_code' => '۱۲۳۴۵۶',
            'biography' => "این یک بیوگرافی نمونه است. اطلاعات تخصصی، تحصیلات و تجارب اینجا نشان داده می‌شود.",
            'comments' => collect([
                (object)['id'=>1,'user'=>'ریحانه','rating'=>5,'text'=>'بسیار مودب و حرفه‌ای بود.'],
                (object)['id'=>2,'user'=>'سید','rating'=>4,'text'=>'خوب بود اما کمی دیر آمد.'],
            ])
        ];

// normalize comments to public property
    $this->comments = $this->doctor->comments ?? [];
}


public function goToBooking($type)
{
// $type can be: phone, chat, flexible
// Decide navigation strategy: redirect to route or emit event
    return redirect()->route('booking.create', [
        'doctor' => $this->doctor->id,
        'type' => $type,
    ]);
}


public function render()
{
    return view('livewire.doctor-detail');
}
}
