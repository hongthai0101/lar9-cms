<meta name="description" content="Latest updates and statistic charts">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<script src="https://ajax.googleapis.com/ajax/libs/webfont/1.6.16/webfont.js"></script>
<script>
    WebFont.load({
        google: {"families":["Poppins:300,400,500,600,700","Roboto:300,400,500,600,700"]},
        active: function() {
            sessionStorage.fonts = true;
        }
    });
</script>
<link href="{{asset('vendor/core/base/metronic')}}/assets/vendors/base/vendors.bundle.css" rel="stylesheet" type="text/css" />
<link href="{{asset('vendor/core/base/metronic')}}/assets/demo/default/base/style.bundle.css" rel="stylesheet" type="text/css" />
<link href="{{ asset('vendor/core/base/css/app.css') }}" rel="stylesheet" type="text/css" />
<link rel="shortcut icon" href="{{get_image_url(setting('__admin_favicon__'))}}" />
<script src="https://ajax.googleapis.com/ajax/libs/webfont/1.6.16/webfont.js"></script>
<script>
    WebFont.load({
        google: {
            "families": ["Poppins:300,400,500,600,700", "Roboto:300,400,500,600,700"]
        },
        active: function() {
            sessionStorage.fonts = true;
        }
    });
</script>
@stack('header')