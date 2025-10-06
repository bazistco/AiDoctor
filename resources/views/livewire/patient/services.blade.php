<div class="p-3 bg-white" >
    <h6 class="fw-bold mb-5" style="font-size: 20px">{{ $title ?? 'چه خدماتی به شما ارائه می‌دهیم؟' }}</h6>

    <div class="row text-center g-3">
        <div class="col-4" >
            <div class="bg-primary bg-opacity-10 rounded-circle p-3 mx-auto" style="width:80px;height:80px;font-size: 40px">
                <a href="{{route('patient.labs')}}">🧪</a>
            </div>
            <span class="border-2 rounded-5 bg-danger-subtle align-content-center text-center" style="position: absolute;margin-top: -100px;margin-right: -50px ;width: 50px;height: 50px;font-size: 15px"> <b>1 ساعته</b> </span>
            <small class="d-block mt-2">ازمایشگاه</small>
        </div>
        <div class="col-4">
            <div class="bg-success bg-opacity-10 rounded-circle p-3 mx-auto" style="width:80px;height:80px; font-size: 40px">
                <a href="{{route('patient.experts')}}">🧠</a>
            </div>
            <small class="d-block mt-2">روانشناسی</small>
        </div>
        <div class="col-4">
            <div class="bg-info bg-opacity-10 rounded-circle p-3 mx-auto" style="width:80px;height:80px;font-size: 40px">
                🩺
            </div>
            <small class="d-block mt-2">پزشکی</small>
        </div>
    </div>
</div>
