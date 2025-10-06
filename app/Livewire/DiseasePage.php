<?php

namespace App\Livewire;

use App\Models\Illness;
use Livewire\Component;

class DiseasePage extends Component
{
    public Illness $disease;
    public $showSurvey = false;
    public $symptoms = [];
    public $aiResult = null;
    public $aiResponse = null;
// پاسخ‌های کاربر
    public $answers = [
        'q1' => null,
        'q2' => '',
        'q3' => null,
    ];
    public  $showForm;

    public function toggleForm()
    {
        $this->showForm = !$this->showForm;
    }
    public function mount()
    {
        // نمونه علائم تستی - بعدا از دیتابیس
        $this->symptoms = [
            'تب' => false,
            'سرفه' => false,
            'تنگی نفس' => false,
            'سردرد' => false,
            'خستگی' => false,
        ];
    }

    public function toggleSurvey()
    {
        $this->showSurvey = !$this->showSurvey;
    }

    public function sendToAi()
    {
        // داده‌ها برای AI آماده میشه
        $selected = array_keys(array_filter($this->symptoms));

        // در عمل اینجا API هوش مصنوعی صدا زده میشه
        $this->aiResult = "بر اساس علائم انتخابی شما (" . implode('، ', $selected) . ")، نیاز به بررسی بیشتر توسط پزشک متخصص وجود دارد.";
    }
    public function submit()
    {
        set_time_limit(300);

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'http://localhost:11434/api/chat',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS =>'{
  "model": "hf.co/mradermacher/Llama-chatDoctor-i1-GGUF:Q4_K_M",
  "messages": [
    {
      "role": "user",
      "content": "The patient has leukemia and has had a fever for the past three weeks.They often feel fatigued and drowsy throughout the day.Occasional headaches and nausea are reported, with a recent weight loss of 3 kg.There is no family history of cancer, and no medications are currently taken."
    }
  ],
  "stream": false
}',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));
        $response = curl_exec($curl);
        curl_close($curl);
        $response=json_decode($response);
        $this->aiResponse =  @translateExample($response->message->content,'en','fa');
        $this->dispatch('close-modal', id: 'symptomModal'); // بستن مودال اول
        $this->dispatch('open-modal', id: 'responseModal'); // باز کردن مودال پاسخ
    }

    public function render()
    {
        return view('livewire.disease-page');
    }
}
