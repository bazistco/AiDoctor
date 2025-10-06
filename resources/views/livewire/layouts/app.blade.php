<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta NAME="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{isset($title) ? $title : 'پنل مراجع'}}</title>
{{--    <link rel="icon" type="image/png" sizes="128x128" href="https://bazistco.com/wp-content/themes/bazist/assets/img/favicon-128x128.png">--}}
    <link rel="stylesheet" href="{{asset('/assets/css/style.jquery-ui.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.bootstrap.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.select.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.scroll.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.icons.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.date.css')}}">
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="{{asset('/assets/css/style.neshan-leaflet.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.sweetalert.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.persian-datepicker.min.css')}}">
    <link rel="stylesheet" type="text/css" href="{{asset('/assets/css/trix.css')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/leaflet.draw.css')}}" />
    <link rel="stylesheet" href="{{asset('/assets/css/leaflet.icon-material.css')}}" />
    <link rel="stylesheet" href="{{asset('/assets/css/style.css?ver=2.0.4')}}">
    <link rel="stylesheet" href="{{asset('/assets/css/style.extra.css?ver=1.0.16')}}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css">

    {{--    <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick.min.css"/>--}}
{{--    <link rel="stylesheet" type="text/css" href="https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick-theme.min.css"/>--}}

    <!-- JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
{{--    <script src="{{asset('/assets/js/jquery.js')}}"></script>--}}
{{--    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/slick-carousel/1.8.1/slick.min.js"></script>--}}
    <script src="{{asset('/assets/js/jquery-ui.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.sweetalert2.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.bootstrap.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.select.js')}}"></script>
    <script src="{{asset('/assets/js/firebase.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.scroll.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.date.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.chart.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.repeatable.js')}}"></script>
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="{{asset('/assets/js/jquery.neshan-leaflet.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.leaflet.geometryutil.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.leaflet-arrowheads.js')}}"></script>
    <script src="{{asset('/assets/js/leaflet.draw.js')}}"></script>
    <script src="{{asset('/assets/js/leaflet.icon-material.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.persian-date.min.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.persian-datepicker.min.js')}}"></script>
    <script type="text/javascript" src="{{asset('/assets/js/trix.umd.min.js')}}"></script>
    <script src="{{asset('/assets/js/jquery.scripts.js?ver=1.0.0')}}"></script>
    @livewireScripts
    @stack('scripts')
</head>
<body>
@isset($slot)
    {{$slot}}
@endisset
</body>
</html>
