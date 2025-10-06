<?php

namespace App\Livewire;

use Livewire\Component;

class SymptomPredictor extends Component
{
    public $symptoms = '';
    public $predictions = [];
    public $loading = false;
    public $errorMessage = null;


    protected $rules = [
        'symptoms' => 'required|string|min:3',
    ];


    public function render()
    {
        return view('livewire.symptom-predictor');
    }


    public function predict()
    {
        $this->reset(['predictions', 'errorMessage']);
        $this->validate();
        $this->loading = true;
        sleep(1); // شبیه‌سازی تاخیر درخواست
        $this->symptoms=translateExample($this->symptoms);
        $predictions = predict_illness($this->symptoms);
        foreach ($predictions as $prediction) {
            sleep(1);
            $this->predictions[]=translateExample($prediction);
        }
        $this->loading = false;
    }
}
