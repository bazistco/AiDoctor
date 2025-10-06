<?php

namespace App\Livewire;

use Livewire\Component;

class LabPacks extends Component
{
    public $step = 1;

    // فرضی: دیتا پک‌ها
    public $packs = [
        [
            'id' => 1,
            'title' => 'پک چکاپ کامل',
            'details' => 'آزمایش خون، قند، چربی، کلیه، کبد',
            'price' => 350000,
            'lab' => 'آزمایشگاه مرکزی',
            'icon' => 'lab.png'
        ],
        [
            'id' => 2,
            'title' => 'پک قند و دیابت',
            'details' => 'آزمایش قند خون ناشتا و HbA1c',
            'price' => 180000,
            'lab' => 'آزمایشگاه دیابت',
            'icon' => 'lab.png'
        ],
    ];

    public function nextStep()
    {
        if ($this->step < 3) {
            $this->step++;
        }
    }

    public function prevStep()
    {
        if ($this->step > 1) {
            $this->step--;
        }
    }

    public function render()
    {
        return view('livewire.lab-packs');
    }
}
