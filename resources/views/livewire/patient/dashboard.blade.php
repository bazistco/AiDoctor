<div class="min-h-screen flex flex-col bg-white" style="margin-bottom: 8%;">
    <livewire:patient.navbar />
    <div class="container mt-5  mb-3 text-center align-content-center" style="width: 80%">
        <livewire:patient.slider />
    </div>
    <livewire:patient.search-bar />
    <livewire:patient.services  />
    <div class="container mt-3  mb-5 text-center align-content-center" style="width: 80%">
        <div id="myCarousel" class="carousel slide" data-bs-ride="carousel" >
            <!-- Indicators -->
            <div class="carousel-indicators">
                <button type="button" data-bs-target="#myCarousel" data-bs-slide-to="0" class="active"></button>
                <button type="button" data-bs-target="#myCarousel" data-bs-slide-to="1"></button>
                <button type="button" data-bs-target="#myCarousel" data-bs-slide-to="2"></button>
            </div>

            <!-- Slides -->
            <div class="carousel-inner text-center">
                <div class="carousel-item active">
                    <a href="{{route('patient.predict')}}"><img src="{{asset('/assets/img/med.jpg')}}" class="d-block w-100" alt="طبیعت ۱"></a>
                </div>
                <div class="carousel-item">
                    <img src="{{asset('/assets/img/med.jpg')}}" class="d-block w-100" alt="کوهستان">
                </div>
                <div class="carousel-item">
                    <img src="{{asset('/assets/img/med.jpg')}}" class="d-block w-100" alt="اقیانوس">
                </div>
            </div>

            <!-- Controls -->
            <button class="carousel-control-prev" type="button" data-bs-target="#myCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" style="color:black"></span>
                <span class="visually-hidden">قبلی</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#myCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" style="color:black"></span>
                <span class="visually-hidden">بعدی</span>
            </button>
        </div>
    </div>
    <div> <livewire:patient.illness /></div>
{{--    <div class="container mt-3  mb-5 text-center align-content-center" style="width: 80%">--}}
{{--        <livewire:patient.slider />--}}

{{--            <div class="slider">--}}
{{--                <div><img src="{{asset('assets/img/med.jpg')}}"></div>--}}
{{--                <div><img src="{{asset('assets/img/med.jpg')}}"></div>--}}


{{--            </div>--}}
{{--            @push('scripts')--}}
{{--                <script>--}}
{{--                    $(document).ready(function() {--}}
{{--                        console.log('ssshshsh')--}}
{{--                        initSlider(); // اجرا در زمان لود اولیه صفحه--}}
{{--                    });--}}

{{--                    Livewire.hook('message.processed', (message, component) => {--}}
{{--                        initSlider();--}}
{{--                    });--}}

{{--                    function initSlider() {--}}
{{--                        if (typeof $ === 'undefined' || typeof $.fn.slick === 'undefined') {--}}
{{--                            console.log('Slick not loaded yet!');--}}
{{--                            return;--}}
{{--                        }--}}

{{--                        if ($('.slider').hasClass('slick-initialized')) return;--}}

{{--                        $('.slider').slick({--}}
{{--                            infinite: true,--}}
{{--                            slidesToShow: 3,--}}
{{--                            slidesToScroll: 1,--}}
{{--                            autoplay: true,--}}
{{--                            autoplaySpeed: 2000,--}}
{{--                            arrows: false,--}}
{{--                            dots: true--}}
{{--                        });--}}
{{--                        console.log('Slider initialized');--}}
{{--                    }--}}
{{--                </script>--}}
{{--            @endpush--}}
{{--        </div>--}}
    <livewire:patient.services  />
    <livewire:patient.bottom-navigation />
</div>





