<?php
namespace App\Livewire;

use Livewire\Component;

class ChatDetails extends Component
{
    public $roomId = 1;
    public $messages = [];
    public $newMessage = '';

    protected $listeners = ['roomSelected' => 'loadRoom'];

    public function loadRoom($id)
    {
        $this->roomId = $id;
        // پیام‌های ساختگی برای هر اتاق
        $this->messages = [
            1 => [
                ['from' => 'me', 'text' => 'سلام از اتاق ۱'],
                ['from' => 'other', 'text' => 'سلام، خوش اومدی!'],
            ],
            2 => [
                ['from' => 'me', 'text' => 'اینجا اتاق ۲ هست'],
            ],
            3 => [
                ['from' => 'other', 'text' => 'این اتاق ۳ هست'],
            ],
        ][$id] ?? [];
    }

    public function sendMessage()
    {
        if (!$this->newMessage) return;

        $this->messages[] = ['from' => 'me', 'text' => $this->newMessage];
        $this->newMessage = '';
    }

    public function render()
    {
        return view('livewire.chat-details');
    }
}

