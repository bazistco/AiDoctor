<div class="min-h-screen flex flex-col bg-white">
    <livewire:patient.navbar />
    <div class="container my-4">

        {{-- پروگرس بار سه مرحله‌ای --}}
        <div class="mb-5">
            <div class="progress" style="height: 30px;">
                <div class="progress-bar bg-success" role="progressbar"
                     style="width: {{ ($step / 3) * 100 }}%">
                    مرحله {{ $step }} از 3
                </div>
            </div>

            <div class="d-flex justify-content-between mt-2">
                <span class="{{ $step >= 1 ? 'fw-bold text-success' : '' }}" style="font-size: large">📦انتخاب پک</span>
                <span class="{{ $step >= 2 ? 'fw-bold text-success' : '' }}" style="font-size: large">🧪انتخاب آزمایشگاه</span>
                <span class="{{ $step >= 3 ? 'fw-bold text-success' : '' }}" style="font-size: large">💳پرداخت</span>
            </div>
        </div>

        {{-- دکمه‌های بالا --}}
        <div class="d-flex gap-2 mb-4">
            <button class="btn btn-outline-primary">
                <i class="bi bi-upload"></i> بارگذاری آزمایش
            </button>
            <button class="btn btn-outline-secondary">
                <i class="bi bi-qr-code"></i> وارد کردن با QR کد
            </button>
        </div>

        {{-- لیست پک‌ها --}}
        @if($step == 1)
            <div class="row">
            @foreach($packs as $pack)
                <div class="col-md-6 mb-4">
                    <div class="card h-100 shadow-sm">
                        <div class="card-body d-flex">
                            <img src="{{ asset('/assets/img/'.$pack['icon']) }}"
                                 alt="icon"  class="lab-icon  my-auto mx-2">

                            <div>
                                <h5 class="card-title">{{ $pack['title'] }}</h5>
                                <p class="text-muted small">{{ $pack['details'] }}</p>
                                <p class="mb-1">
                                    <strong>آزمایشگاه:</strong> {{ $pack['lab'] }}
                                </p>
                                <p class="mb-2">
                                    <strong>مبلغ:</strong> {{ number_format($pack['price']) }} تومان
                                </p>
                                <button class="btn btn-success btn-sm">
                                    <i class="bi bi-plus-circle"></i> افزودن
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        @endif
        {{-- مرحله ۲: انتخاب آزمایشگاه --}}
        {{-- مرحله ۲: انتخاب آزمایشگاه --}}
        @if($step == 2)
            <div class="row">
                <div class="col-md-6 mb-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body d-flex justify-content-between align-items-start">
                            {{-- سمت چپ: عنوان و آدرس --}}
                            <div>
                                <h5 class="card-title mb-1">آزمایشگاه مرکزی</h5>
                                <p class="text-muted small mb-0">خیابان انقلاب، تهران</p>
                            </div>
                            {{-- سمت راست: لوگو رندوم --}}
                            <img src="https://picsum.photos/60/60?random=1" class="rounded-circle" alt="logo">
                        </div>

                        {{-- دکمه پایین وسط --}}
                        <div class="card-footer bg-transparent border-0 text-center">
                            <button class="btn btn-outline-success">
                                انتخاب
                            </button>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title mb-1">آزمایشگاه دیابت</h5>
                                <p class="text-muted small mb-0">خیابان ولیعصر، تهران</p>
                            </div>
                            <img src="https://picsum.photos/60/60?random=2" class="rounded-circle" alt="logo">
                        </div>
                        <div class="card-footer bg-transparent border-0 text-center">
                            <button class="btn btn-outline-success">
                                انتخاب
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- مرحله ۳: پرداخت --}}
        @if($step == 3)
            <div class="card p-4 shadow-sm">
                <h5>خلاصه سفارش</h5>
                <ul>
                    <li>پک انتخاب شده: پک چکاپ کامل</li>
                    <li>آزمایشگاه: آزمایشگاه مرکزی</li>
                    <li>مبلغ کل: 350,000 تومان</li>
                </ul>
                <button class="btn btn-success">پرداخت</button>
            </div>
        @endif

        {{-- دکمه مرحله بعد --}}
        <div class="d-flex justify-content-between mt-4">
            @if($step > 1)
                <button wire:click="prevStep" class="btn btn-outline-secondary">مرحله قبل</button>
            @endif

            @if($step < 3)
                <button wire:click="nextStep" class="btn btn-primary">مرحله بعد</button>
            @else
                <button class="btn btn-success">پرداخت</button>
            @endif
        </div>
    </div>
    <livewire:patient.bottom-navigation />
</div>
