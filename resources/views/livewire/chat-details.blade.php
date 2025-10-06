<div class="chat-details">
    <div class="chat-header">
        اتاق {{ $roomId }}
    </div>

    <div class="chat-messages">
        @foreach($messages as $msg)
            <div class="message {{ $msg['from'] }}">
                {{ $msg['text'] }}
            </div>
        @endforeach
    </div>

    <div class="chat-input">
        <input type="text" wire:model="newMessage" placeholder="پیام خود را بنویسید..." wire:keydown.enter="sendMessage">
        <button wire:click="sendMessage">ارسال</button>
    </div>
</div>
