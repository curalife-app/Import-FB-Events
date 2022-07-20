@push('head')

    @if (parse_url(config('app.url'), PHP_URL_SCHEME) != 'https')
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    @endif

    <link
        href="/favicon.png"
        id="favicon"
        rel="icon"
    >
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&amp;display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <style>
        body, .popover-body {
            font-family: "Poppins",sans-serif;
        }
    </style>
@endpush

<p class="h2 n-m font-thin v-center">
    <img src="https://cdn.shopify.com/s/files/1/0495/2621/0723/files/logo-colored_201b4ca3-0ff6-4c76-ab65-5033659c30e1.png?v=1620372592" width="110">
</p>
