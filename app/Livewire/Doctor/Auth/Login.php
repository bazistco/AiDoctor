<?php

namespace App\Livewire\Doctor\Auth;

use App\Models1\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class Login extends Component
{
 public function render(){
     return view('livewire.doctor.auth.login');
 }
    public function login()
    {

        $data = $this->validate([
            'email' => 'required|email|exists:users,email',
        ], [
            'email.required' => 'لطفا ایمیل را وارد نمایید',
            'email.email' => 'لطفا ایمیل معتبر وارد نمایید',
            'email.exists' => 'ایمیل و یا رمزعبور صحیح نمی باشد',
        ]);
        $user = User::where('email', $this->email)->first();
        if (!Hash::check($this->password, $user->password)) {
            sendToast(0, 'ایمیل و یا رمزعبور صحیح نمی باشد');
        } else {
            if (Auth::loginUsingId($user->id, true)) {
                $this->textBtn = 'درحال ورود به حساب کاربری...';
                $this->iconBtn = '';
                return $this->redirect(route('doctor.dashboard'));
            } else {
                return sendToast(0, 'ورود با اشکال روبرو شد لطفا صفحه را یکبار رفرش کنید و دوباره امتحان کنید');
            }
        }
    }
}
