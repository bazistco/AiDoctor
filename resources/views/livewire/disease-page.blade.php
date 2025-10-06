
<div class="min-h-screen flex flex-col bg-white">
    <livewire:patient.navbar />
    <livewire:patient.search-bar />
    {{-- دکمه ویژه ورود به پرسشنامه --}}
    <div class="text-center mb-4">
        <button class="btn btn-lg btn-primary shadow-lg px-5 py-3 rounded-pill"
                data-bs-toggle="modal" data-bs-target="#symptomModal"
                style="font-size: 1.3rem; background: linear-gradient(90deg, #4da6ff, #66ccff); border:none;">
            🤖 ورود به پرسشنامه هوش مصنوعی
        </button>
    </div>

    {{-- Modal پرسشنامه --}}
    <div wire:ignore.self class="modal fade" id="symptomModal" tabindex="-1" aria-labelledby="symptomModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white" style="font-size: 1.3rem; background: linear-gradient(90deg, #4da6ff, #66ccff); border:none;">
                    <h5 class="modal-title" id="symptomModalLabel">پرسشنامه علائم {{ $disease->name ?? 'سرطان' }}</h5>
                </div>
                <div class="modal-body" style="max-height: 400px; overflow-y: auto;">

                    {{-- سوال چهارگزینه‌ای --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۱. شدت درد شما در چه حد است؟</label>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q1" value="کم"><label class="form-check-label">کم</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q1" value="متوسط"><label class="form-check-label">متوسط</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q1" value="زیاد"><label class="form-check-label">زیاد</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q1" value="خیلی شدید"><label class="form-check-label">خیلی شدید</label></div>
                        </div>
                    </div>

                    {{-- سوال تشریحی --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۲. از چه زمانی این علائم را تجربه می‌کنید؟</label>
                        <input type="text" class="form-control" wire:model="answers.q2" placeholder="مثلا: ۳ روز">
                    </div>

                    {{-- سوال بله/خیر --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۳. آیا تب دارید؟</label>
                        <div class="d-flex gap-3">
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q3" value="بله"><label class="form-check-label">بله</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q3" value="خیر"><label class="form-check-label">خیر</label></div>
                        </div>
                    </div>

                    {{-- سوال چندگزینه‌ای دیگر --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۴. بیشتر علائم شما در کدام ناحیه است؟</label>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q4" value="سر"><label class="form-check-label">سر</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q4" value="شکم"><label class="form-check-label">شکم</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q4" value="قفسه سینه"><label class="form-check-label">قفسه سینه</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q4" value="پاها"><label class="form-check-label">پاها</label></div>
                        </div>
                    </div>

                    {{-- سوال تشریحی --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۵. آیا داروی خاصی مصرف می‌کنید؟</label>
                        <input type="text" class="form-control" wire:model="answers.q5" placeholder="نام دارو">
                    </div>

                    {{-- سوال بله/خیر --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۶. سابقه بیماری مشابه در خانواده دارید؟</label>
                        <div class="d-flex gap-3">
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q6" value="بله"><label class="form-check-label">بله</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q6" value="خیر"><label class="form-check-label">خیر</label></div>
                        </div>
                    </div>

                    {{-- سوال چندگزینه‌ای دیگر --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۷. بیشتر علائم در چه زمانی از روز شدت دارند؟</label>
                        <div class="d-flex gap-3 flex-wrap">
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q7" value="صبح"><label class="form-check-label">صبح</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q7" value="ظهر"><label class="form-check-label">ظهر</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q7" value="عصر"><label class="form-check-label">عصر</label></div>
                            <div class="form-check"><input class="form-check-input" type="radio" wire:model="answers.q7" value="شب"><label class="form-check-label">شب</label></div>
                        </div>
                    </div>

                    {{-- سوال تشریحی --}}
                    <div class="mb-3">
                        <label class="form-label fw-bold">۸. توضیحات دیگری در مورد علائم خود دارید؟</label>
                        <textarea class="form-control" wire:model="answers.q8" rows="2" placeholder="توضیحات خود را وارد کنید..."></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                    <div class="cr-button wd">
                        {{load_button('ارسال 🚀','','wire:click="submit"')}}
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal پاسخ -->
    <div wire:ignore.self class="modal fade" id="responseModal" tabindex="-1" aria-labelledby="responseModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="responseModalLabel">نتیجه پرسشنامه 🤖</h5>
                </div>
                <div class="modal-body text-center p-4">
                    <div wire:loading>
                        <div class="spinner-border text-success mb-3" role="status"></div>
                        <p>در حال تحلیل پاسخ‌ها توسط هوش مصنوعی...</p>
                    </div>
                    <div wire:loading.remove>
                        @if($aiResponse)
                            <p class="text-dark fw-bold">{{ $aiResponse }}</p>
                        @else
                            <p class="text-muted">هنوز پاسخی دریافت نشده است.</p>
                        @endif
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" data-bs-dismiss="modal">بستن</button>
                </div>
            </div>
        </div>
    </div>

    <script>
            window.addEventListener('close-modal', event => {
            const modalId = event.detail.id;
            const modalEl = document.getElementById(modalId);
            if (!modalEl) return;
            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.hide();
        });

            window.addEventListener('open-modal', event => {
            const modalId = event.detail.id;
            const modalEl = document.getElementById(modalId);
            if (!modalEl) return;
            const modal = new bootstrap.Modal(modalEl);
            modal.show();
        });
    </script>
    <livewire:doctors-list :disease="$disease" />
    <livewire:patient.bottom-navigation />
</div>
