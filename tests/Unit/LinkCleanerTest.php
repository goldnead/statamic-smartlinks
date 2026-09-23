<?php

use Goldnead\Smartlinks\LinkCleaner;

it('strips foreign affiliate and tracking parameters and normalises the URL form', function (string $in, string $out) {
    expect(app(LinkCleaner::class)->clean($in))->toBe($out);
})->with([
    // The stored ANDERS form: Odesli's affiliate token on every Apple link.
    'Apple via Odesli' => [
        'https://geo.music.apple.com/de/album/_/1491803543?i=1491803545&mt=1&app=itunes&ls=1&at=1000lHKX&ct=api_http&itscg=30200&itsct=odsl_m',
        'https://music.apple.com/de/album/_/1491803543?i=1491803545',
    ],
    'Apple lookup form' => ['https://music.apple.com/de/album/alles-wird-gut/1530381797?i=1530381799&uo=4', 'https://music.apple.com/de/album/alles-wird-gut/1530381797?i=1530381799'],
    'old iTunes id prefix' => ['https://itunes.apple.com/de/album/x/id1530381797?i=1530381799', 'https://music.apple.com/de/album/x/1530381797?i=1530381799'],
    'Spotify si and intl' => ['https://open.spotify.com/intl-de/track/1w0r0NDXEByTCr7wa5HjNK?si=abc123', 'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK'],
    'Spotify URI' => ['spotify:track:1w0r0NDXEByTCr7wa5HjNK', 'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK'],
    'Deezer language prefix' => ['https://www.deezer.com/de/track/1069843552?utm_source=x&utm_medium=y', 'https://www.deezer.com/track/1069843552'],
    'Tidal listen and browse' => ['https://listen.tidal.com/browse/track/172804280/u', 'https://tidal.com/track/172804280'],
    'Tidal listen' => ['https://listen.tidal.com/track/172804280', 'https://tidal.com/track/172804280'],
    'YouTube keeps v, drops utm and si' => ['https://www.youtube.com/watch?v=yG4VfxlXbIc&utm_campaign=x&si=y', 'https://www.youtube.com/watch?v=yG4VfxlXbIc'],
    'unknown host keeps its query' => ['https://www.boomplay.com/songs/44031875?from=home', 'https://www.boomplay.com/songs/44031875?from=home'],
    'fragment is kept' => ['https://soundcloud.com/anders/song?utm_source=a#t=10', 'https://soundcloud.com/anders/song#t=10'],
    'Anghami linktree referrer' => ['https://play.anghami.com/song/1447428?refer=linktree', 'https://play.anghami.com/song/1447428'],
    'generic click ids' => ['https://www.boomplay.com/songs/1?msclkid=a&ttclid=b&mc_cid=c&ref=home&keep=1', 'https://www.boomplay.com/songs/1?keep=1'],
    'already clean' => ['https://www.deezer.com/track/1', 'https://www.deezer.com/track/1'],
    'not a URL is left alone' => ['javascript:alert(1)', 'javascript:alert(1)'],
]);

it('leaves an URL it has nothing to remove from byte for byte alone', function (string $url) {
    expect(app(LinkCleaner::class)->clean($url))->toBe($url)
        ->and(app(LinkCleaner::class)->cleanRows([['url' => $url]], 'url')[1])->toBe(0);
})->with([
    'dots in keys' => ['https://www.boomplay.com/songs/1?a.b=1&x=%2F'],
    'array-like keys' => ['https://example.com/p?list[]=1&list[]=2'],
    'plus and encoding' => ['https://soundcloud.com/a/b?in=x+y%20z'],
    'empty value' => ['https://example.com/p?flag'],
]);

it('removes only the unwanted pieces and keeps the rest as written', function () {
    expect(app(LinkCleaner::class)->clean('https://www.boomplay.com/songs/1?a.b=1&utm_source=x&x=%2F'))
        ->toBe('https://www.boomplay.com/songs/1?a.b=1&x=%2F');
});

it('drops rows that become duplicates after cleaning', function () {
    [$rows, $changed] = app(LinkCleaner::class)->cleanRows([
        ['platform' => 'applemusic', 'url' => 'https://geo.music.apple.com/de/album/_/1491803543?i=1491803545&mt=1&app=itunes&at=1000lHKX'],
        ['platform' => 'applemusic', 'url' => 'https://geo.music.apple.com/de/album/_/1491803543?i=1491803545&mt=1&app=music&at=1000lHKX'],
        ['platform' => 'deezer', 'url' => 'https://www.deezer.com/track/1'],
    ], 'url');

    expect($rows)->toBe([
        ['platform' => 'applemusic', 'url' => 'https://music.apple.com/de/album/_/1491803543?i=1491803545'],
        ['platform' => 'deezer', 'url' => 'https://www.deezer.com/track/1'],
    ])->and($changed)->toBe(2);
});

it('keeps parameters on the allow-list, and strips extra ones from config', function () {
    config(['smartlinks.cleanup.keep' => ['at'], 'smartlinks.cleanup.strip' => ['from']]);
    $cleaner = new LinkCleaner;

    expect($cleaner->clean('https://music.apple.com/de/album/x/1?i=2&at=MINE&ct=y'))->toBe('https://music.apple.com/de/album/x/1?i=2&at=MINE')
        ->and($cleaner->clean('https://www.boomplay.com/songs/1?from=home'))->toBe('https://www.boomplay.com/songs/1');
});
