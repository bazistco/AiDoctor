<?php

namespace App\Livewire;

use Livewire\Component;

class TopDoctors extends Component
{
    public $doctors = [];


    public function mount()
    {
        $this->loadTopDoctors();
    }
protected function loadTopDoctors()
{
// Example: get top doctors by rating and successful visits
    $this->doctors = collect([]);


// fallback sample data for development
    if ($this->doctors->isEmpty()) {
        $this->doctors = collect([
            (object)['id'=>1,'name'=>'دکتر نادری','specialty'=>'قلب و عروق','avatar'=>'/images/doctor1.png','avg_rating'=>4.9,'successful_visits'=>320],
            (object)['id'=>2,'name'=>'دکتر شریفی','specialty'=>'اعصاب و روان','avatar'=>'/images/doctor2.png','avg_rating'=>4.8,'successful_visits'=>290],
            (object)['id'=>3,'name'=>'دکتر مرادی','specialty'=>'گوارش','avatar'=>'/images/doctor3.png','avg_rating'=>4.7,'successful_visits'=>265],
        ]);
    }
}


public function goToProfile($doctorId)
{
    return redirect()->route('patient.doctor_detail', ['doctorId' => $doctorId]);
}


public function render()
{
    return view('livewire.top-doctors');
}
}
