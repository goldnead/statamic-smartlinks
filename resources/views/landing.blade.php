{{--
    The smart link page. Deliberately plain: publish it
    (php artisan vendor:publish --tag=smartlinks-views) or point
    `smartlinks.routes.view` at an Antlers template of the site.

    Receives: $entry, $title, $links (platform, url, label, icon, click_url).
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f5f5f4; color: #1c1917; }
        main { max-width: 28rem; margin: 0 auto; padding: 3rem 1rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        p { margin: 0 0 1.5rem; color: #57534e; }
        ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
        a { display: block; padding: .875rem 1rem; background: #fff; border: 1px solid #e7e5e4; border-radius: .5rem; color: inherit; text-decoration: none; font-weight: 500; }
        a:hover, a:focus-visible { border-color: #1c1917; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $title }}</h1>
        <p>{{ __('smartlinks::messages.choose') }}</p>

        @if (count($links) === 0)
            <p>{{ __('smartlinks::messages.none') }}</p>
        @else
            <ul>
                @foreach ($links as $link)
                    <li>
                        <a href="{{ $link['click_url'] ?? $link['url'] }}" rel="nofollow" data-platform="{{ $link['platform'] }}">{{ $link['label'] }}</a>
                    </li>
                @endforeach
            </ul>
        @endif
    </main>
</body>
</html>
