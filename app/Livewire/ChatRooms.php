<?php
namespace App\Livewire;

use Livewire\Component;

class ChatRooms extends Component
{
    public function __construct()
    {
        dd('ss');
    }

    public $rooms = [
        ['id' => 1, 'name' => 'اتاق ۱'],
        ['id' => 2, 'name' => 'اتاق ۲'],
        ['id' => 3, 'name' => 'اتاق ۳'],
    ];

    public $activeRoom = 1;

    public function selectRoom($id)
    {
        $this->activeRoom = $id;
        $this->emit('roomSelected', $id);
    }

    public function render()
    {
        $rooms=$this->rooms;
        return view('livewire.chat-rooms',compact('rooms'));
    }
}
