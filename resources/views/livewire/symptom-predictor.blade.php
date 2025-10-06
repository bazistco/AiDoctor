<div class="min-h-screen flex flex-col bg-white">
    <livewire:patient.navbar />
    <livewire:patient.search-bar />

        <div class="container  rounded-2 m-auto" style="margin-bottom: 100px">
            <h2 class="text-xl font-semibold mb-2">ورود علائم</h2>
            <p class="text-lg-end text-gray-500 mb-4">علایم را داخل کادر بنویسید (مثال: تب، گلودرد، سردرد، خستگی)</p>
          <div>            <div class="cr-textarea"  >
            <textarea
                wire:model.debounce.500ms="symptoms"
                wire:keydown.enter.prevent="predict"
                placeholder="مثال: تب 38، گلودرد، تورم غدد لنفاوی..."
            ></textarea>
              </div>
          </div>
            <div class="row">
            <div class="cr-button col-2 mx-1 my-2">
                {{load_button('پیش بینی بیماری 🔮','','wire:click="predict"')}}
            </div>

                {{--            <div wire:loading wire:target="predict" class="flex items-center gap-2">--}}
                {{--                <svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">--}}
                {{--                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>--}}
                {{--                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>--}}
                {{--                </svg>--}}
                {{--                <span class="text-sm">در حال پردازش...</span>--}}
                {{--            </div>--}}
            </div>

            @if($errorMessage)
                <div class="mt-3 text-sm text-red-600">{{ $errorMessage }}</div>
            @endif

            <template x-if="false"></template>

            <div class="mt-4">
                <h3 class="fw-semibold mb-3">نتایج پیشنهادی</h3>

                @if(empty($predictions))
                    <div class="text-muted">هیچ پیشنهادی وجود ندارد. ابتدا دکمه پیش‌بینی را بزنید.</div>
                @else
                    <div class="row g-3">
                        @foreach($predictions as $item)
                            <div class="col-12 col-md-6 col-lg-4">
                                <div class="card h-100 shadow-sm border-0">
                                    <div class="card-body d-flex flex-column justify-content-between">
                                        <div>
                                            <h5 class="card-title fw-bold text-primary">
                                                {{ $item ?? 'نام نامشخص' }}
                                            </h5>
                                            @if(isset($item['explanation']) and !empty($item['explanation']))
                                                <p class="card-text text-secondary small mt-2">
                                                    {{ $item['explanation'] }}
                                                </p>
                                            @endif
                                        </div>
                                        <div class="mt-3">
                                <span class="badge bg-info text-dark px-3 py-2">
                                    @if(isset($item['confidence']) and !is_null($item['confidence']))
                                        {{ $item['confidence'] }}%
                                    @else
                                        بدون اطمینان
                                    @endif
                                </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
    </div>
    <livewire:patient.bottom-navigation />
</div>


