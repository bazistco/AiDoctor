<div class="p-3 bg-white" >
    <h4 class="fw-bold ">👨‍⚕️ دکترهای برتر</h4>
    <br>
    <div class="container  text-center " dir="rtl">
        <div class="row  text-center">
            @foreach($doctors as $doc)
                <div class="col-md-4 col-lg-4 p-5">
                    <div class="card h-80 text-center border-1 shadow-lg p-3">
                        <img src="{{asset('assets/img/doc_female.png')}}" alt="doctor" class="rounded-circle mx-auto mb-2" style="width:100px;height:100px;object-fit:cover;">
                        <h6 class="fw-bold mb-0">{{ $doc->name }}</h6>
                        <p class="text-muted small mb-2">{{ $doc->specialty }}</p>
                        <div class="d-flex justify-content-center align-items-center small text-warning mb-2">
                            ⭐ {{ number_format($doc->avg_rating, 1) }}
                        </div>
                        <p class="text-muted small mb-2">ویزیت موفق: {{ $doc->successful_visits }}</p>
                        <button wire:click="goToProfile({{ $doc->id }})" class="btn btn-outline-primary btn-sm">مشاهده پروفایل</button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
