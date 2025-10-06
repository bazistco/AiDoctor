<div class="min-h-screen flex flex-col bg-white">
    <livewire:patient.navbar />
{{--    <div class="chat-container">--}}
{{--        <livewire:chat-rooms />--}}
{{--        <livewire:chat-details />--}}
{{--    </div>--}}
    <div class="container-fluid vh-100">
        <div class="row h-100 bg-light">

            <!-- لیست چت‌ها (سمت راست) -->
            <div class="col-12 col-md-4 col-lg-3 border-start d-flex flex-column p-0">

                <!-- نوار جستجو -->
                <div class="p-3 border-bottom bg-white">
                    <input type="text" class="form-control form-control-sm rounded-pill" placeholder="جستجو در چت‌ها...">
                </div>

                <!-- لیست اتاق‌ها -->
                <div class="list-group list-group-flush flex-grow-1 overflow-auto">
                    <a href="#"
                       class="list-group-item list-group-item-action d-flex align-items-center border-0 border-bottom active">
                        <img src="{{asset('assets/img/doc_female.png')}}" class="rounded-circle me-2" width="40" height="40">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <strong>دکتر x</strong>
                                <small class="text-muted">14:20</small>
                            </div>
                            <small class="text-muted">آخرین پیام...</small>
                        </div>
                    </a>
                    <a href="#"
                       class="list-group-item list-group-item-action d-flex align-items-center border-0 border-bottom">
                        <img src="{{asset('assets/img/doc_male.jpg')}}" class="rounded-circle me-2" width="40" height="40">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <strong>دکتر y</strong>
                                <small class="text-muted">دیروز</small>
                            </div>
                            <small class="text-muted">سلام خوبی؟</small>
                        </div>
                    </a>
                </div>
            </div>

            <!-- بخش چت (سمت چپ) -->
            <div class="col-12 col-md-8 col-lg-9 d-flex flex-column p-0">

                <!-- هدر چت -->
                <div class="d-flex align-items-center p-3 border-bottom bg-white">
                    <img src="{{asset('assets/img/doc_female.png')}}" class="rounded-circle me-2" width="40" height="40">
                    <div>
                        <div><strong>اتاق ۱</strong></div>
                        <small class="text-muted">آنلاین</small>
                    </div>
                </div>

                <!-- پیام‌ها -->
                <div class="flex-grow-1 overflow-auto p-3 bg-chat">
                    <div class="d-flex flex-column">

                        <div class="p-2 rounded-3 mb-2 bg-success text-white align-self-start" style="max-width: 70%;">
                            سلام! حالت چطوره؟
                        </div>

                        <div class="p-2 rounded-3 mb-2 bg-white shadow-sm align-self-end" style="max-width: 70%;">
                            مرسی، خیلی خوبم. تو چطوری؟
                        </div>

                        <div class="p-2 rounded-3 mb-2 bg-success text-white align-self-start" style="max-width: 70%;">
                            عالی، دمت گرم 🌹
                        </div>

                    </div>
                </div>

                <!-- اینپوت پیام -->
                <div class="p-3 border-top bg-white">
                    <div class="input-group">
                        <input type="text" class="form-control rounded-pill" placeholder="پیام خود را بنویسید...">
                        <button class="btn btn-success rounded-pill ms-2 px-4">ارسال</button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <livewire:patient.bottom-navigation />
</div>





