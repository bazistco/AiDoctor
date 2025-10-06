<div wire:ignore.self>
    <div class="slider" wire:ignore.self >
        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>
        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>
        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>
        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>
        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>
        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>
        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>

        <div wire:ignore.self><img onclick="sos()" src="{{ asset('assets/img/med.jpg') }}" alt="اسلاید" wire:ignore.self></div>
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
                slidesToShow: 8,
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
