<div wire:ignore.self>
    <div class="slider" wire:ignore.self >
        <div class="py-1"><div class=" mx-auto" style="width:60px;height:60px;font-size: 60px">
                🩺
            </div>
            <small class="d-block   mx-auto mt-3 text-bold  ">پزشکی</small></div>
        <div >
            <div class=" mx-auto" style="width:60px;height:60px;font-size: 60px">
                🧠
            </div>
            <small class="d-block mx-auto mt-3 text-bold ">مشاوره</small>
        </div>
        <div class="py-1"><div class=" mx-auto" style="width:60px;height:60px;font-size: 60px">
                💊
            </div>
            <small class="d-block mx-auto mt-3 text-bold">داروخانه</small>
        </div>
        <div class="py-1" >
            <div class=" mx-auto" style="width:60px;height:60px;font-size: 60px">
                🔬
            </div>
            <small class="d-block mx-auto mt-3 text-bold">آزمایشگاه</small>
        </div>
        <div class="py-1" ><div class=" mx-auto" style="width:60px;height:60px;font-size: 60px">
                🏠
            </div>
            <small class="d-block mx-auto mt-3 text-bold">پرستار در منزل</small></div>
    </div>
</div>

@push('scripts')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick.min.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick-theme.min.css"/>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick.min.js"></script>

    <script>
        function sos(){
            alert("sdsds")
        }
        $(document).ready(function() {
            initSlider();
            const sliderImages = document.querySelectorAll('.slider-img');

            sliderImages.forEach((img, index) => {
                img.addEventListener('click', () => {
                    alert('You clicked image #' + (index + 1));
                });
            });
        });
        window.addEventListener('image-clicked', event => {
            alert('You clicked on image #' + event.detail.index);
        });

        Livewire.hook('message.processed', (message, component) => {
            initSlider();
        });

        function initSlider() {
            if (typeof $ === 'undefined' || typeof $.fn.slick === 'undefined') return;
            if ($('.slider').hasClass('slick-initialized')) return;

            $('.slider').slick({
                infinite: true,
                slidesToShow: 5,
                slidesToScroll: 1,
                autoplay: true,
                autoplaySpeed: 2000,
                arrows: false,
                dots: true,
                responsive: [
                    { breakpoint: 1024, settings: { slidesToShow: 3 } },
                    { breakpoint: 768,  settings: { slidesToShow: 2 } },
                    { breakpoint: 480,  settings: { slidesToShow: 1 } }
                ]
            });
        }
    </script>
@endpush
