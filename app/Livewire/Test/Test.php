<?php

namespace App\Livewire\Test;

use Livewire\Attributes\Title;
use Livewire\Component;

class Test extends Component
{
    #[Title('فعالیت ها')]
    public function render()
    {
        return view('livewire.test.test');
    }
}
