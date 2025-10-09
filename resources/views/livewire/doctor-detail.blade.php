<div class="min-h-screen flex flex-col bg-white">
    <livewire:patient.navbar />
    <livewire:patient.search-bar />
    <div class="container py-3" dir="rtl" style="margin-bottom: 150px">
        <div class="row">
            <!-- right column: avatar + name + specialty -->
            <div class="col-md-4 text-center">
                <div class="card p-3">
                    <img src="{{asset('assets/img/doc_female.png')}}" alt="avatar" class="rounded-circle img-fluid" style="width:140px;height:140px;object-fit:cover;margin:auto;">
                    <h5 class="mt-3 mb-0">{{ $doctor->name }}</h5>
                    <p class="text-muted">{{ $doctor->specialty }}</p>
                </div>
            </div>

            <!-- left column: stats -->
            <div class="col-md-8">
                <div class="d-flex mb-3">
                    <div class=" p-3 text-center border-1  rounded shadow-sm" style="min-width:140px;">
                        <div class="fw-bold">ویزیت موفق✅</div>
                        <div class="h4">{{ number_format($doctor->successful_visits) }}</div>
                    </div>

                    <div class="me-4 p-3 text-center border-1  rounded shadow-sm" style="min-width:140px;">
                        <div class="fw-bold">میانگین امتیاز⭐</div>
                        <div class="h4">{{ number_format($doctor->avg_rating, 1) }} / 5</div>
                    </div>

                    <div class="me-4 p-3 text-center border-1  rounded shadow-sm" style="min-width:160px;">
                        <div class="fw-bold"> کد نظام پرستاری🆔</div>
                        <div class="h4">{{ $doctor->nursing_code }}</div>
                    </div>
                </div>

                <!-- biography -->
                <div class="card mb-3 border-1  rounded shadow-sm ">
                    <div class="card-header "><h4>درباره من</h4></div>
                    <div class="card-body">
                        <p class="mb-0">{!! nl2br(e($doctor->biography)) !!}</p>
                    </div>
                </div>

                <!-- reservation types row -->
                <div class="card mb-3 border-1  rounded shadow-sm ">
                    <div class="card-header"><h4>روش‌های رزرو</h4></div>
                    <div class="card-body">
                        <div class="row text-center gy-3">
                            <div class="col-md-4">
                                <div class="border-2  rounded shadow-sm p-3 h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <h6>تلفنی</h6>
                                        <p class="text-muted small mb-0">رزرو تماس تلفنی با پزشک</p>
                                    </div>
                                    <div>
                                        <button wire:click="goToBooking('phone')" class="btn btn-primary btn-sm mt-2">رزرو تلفنی</button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border-2  rounded shadow-sm p-3 h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <h6>چتی</h6>
                                        <p class="text-muted small mb-0">گفت‌وگوی متنی با پزشک</p>
                                    </div>
                                    <div>
                                        <button wire:click="goToBooking('chat')" class="btn btn-success btn-sm mt-2">رزرو چت</button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <div class="border-2  rounded shadow-sm p-3 h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <h6>زمان دلخواه</h6>
                                        <p class="text-muted small mb-0">انتخاب تاریخ و ساعت دلخواه</p>
                                    </div>
                                    <div>
                                        <button wire:click="goToBooking('flexible')" class="btn btn-outline-primary btn-sm mt-2">انتخاب زمان</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- comments -->
                <div class="card border-1  rounded shadow-sm ">
                    <div class="card-header">نظرات کاربران</div>
                    <div class="card-body">
                        @if(count($comments))
                            @foreach($comments as $c)
                                <div class="mb-3 border-bottom pb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div><strong>{{ $c->user }}</strong></div>
                                        <div class="small">امتیاز: {{ $c->rating }}⭐</div>
                                    </div>
                                    <p class="mb-0 mt-1">{{ $c->text }}</p>
                                </div>
                            @endforeach
                        @else
                            <p class="text-muted">هنوز نظری ثبت نشده است.</p>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </div>

    <livewire:patient.bottom-navigation />
</div>
