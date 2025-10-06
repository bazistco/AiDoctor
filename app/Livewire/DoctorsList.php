<?php

namespace App\Livewire;

use AllowDynamicProperties;
use Livewire\Component;

class DoctorsList extends Component
{
    public $inPerson = false;
    public $urgent = false;
    public $phone = false;
    public $male = false;
    public $female = false;

    public $doctors = [];
    public $disease;

    public function mount($disease=null)
    {
        // نمونه دیتا - در عمل از دیتابیس میاری
        $this->disease = $disease;

        // اینجا پزشکان رو بر اساس بیماری فیلتر می‌کنی
        $this->doctors = collect([
            ['name' => 'دکتر علی رضایی', 'specialty' => 'قلب', 'rating' => 4.8, 'gender'=>'male', 'services'=>['inPerson'],'diseases'=>['قلب']],
            ['name' => 'دکتر نسرین محمدی', 'specialty' => 'پوست', 'rating' => 4.6, 'gender'=>'female', 'services'=>['urgent'],'diseases'=>['اگزما','آکنه']],
            ['name' => 'دکتر سارا کریمی', 'specialty' => 'روانپزشک', 'rating' => 4.9, 'gender'=>'female', 'services'=>['phone'],'diseases'=>['افسردگی','اضطراب']],
        ])
            ->when($this->disease, fn($q) => $q->filter(fn($doc) => in_array($this->disease, $doc['diseases'])))
            ->toArray();
    }

    public function getFilteredDoctorsProperty()
    {
        return collect($this->doctors)->filter(function ($doctor) {
            if ($this->inPerson && !in_array('inPerson', $doctor['services'])) return false;
            if ($this->urgent && !in_array('urgent', $doctor['services'])) return false;
            if ($this->phone && !in_array('phone', $doctor['services'])) return false;

            if ($this->male && $doctor['gender'] !== 'male') return false;
            if ($this->female && $doctor['gender'] !== 'female') return false;

            return true;
        })->toArray();
    }

    public function render()
    {
        return view('livewire.doctors-list');
    }
}
