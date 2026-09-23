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
    'already clean' => ['https://www.deezer.com/track/1', 'https://www.deezer.com/track/1'],
    'not a URL is left alone' => ['javascript:alert(1)', 'javascript:alert(1)'],
]);

it('keeps parameters on the allow-list, and strips extra ones from config', function () {
    config(['smartlinks.cleanup.keep' => ['at'], 'smartlinks.cleanup.strip' => ['from']]);
    $cleaner = new LinkCleaner;

    expect($cleaner->clean('https://music.apple.com/de/album/x/1?i=2&at=MINE&ct=y'))->toBe('https://music.apple.com/de/album/x/1?i=2&at=MINE')
        ->and($cleaner->clean('https://www.boomplay.com/songs/1?from=home'))->toBe('https://www.boomplay.com/songs/1');
});
