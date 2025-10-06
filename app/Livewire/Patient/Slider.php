<?php

namespace App\Livewire\Patient;

use Livewire\Component;

class Slider extends Component
{

    public $images = [];

    public function mount()
    {
        // می‌تونی عکس‌ها رو از دیتابیس هم بیاری
        $this->images = [
            '/assets/img/med.jpg',
            '/assets/img/med.jpg',
            '/assets/img/med.jpg',
        ];
    }
    public function render()
    {
        return view('livewire.patient.slider');
    }
}
