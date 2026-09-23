<?php

use Goldnead\Smartlinks\Platforms;
use Goldnead\Smartlinks\Resolvers\Track;

dataset('urls', [
    // the tricky ones
    'YouTube Music, not YouTube' => ['https://music.youtube.com/watch?v=yG4VfxlXbIc', 'youtubemusic'],
    'Spotify with intl segment' => ['https://open.spotify.com/intl-de/track/1w0r0NDXEByTCr7wa5HjNK', 'spotify'],
    'Apple Music geo link' => ['https://geo.music.apple.com/de/album/x/123?i=456', 'applemusic'],
    'Spotify share redirect' => ['https://link.tospotify.com/abcDEF', 'spotify'],
    // the ANDERS table
    'Spotify' => ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK', 'spotify'],
    'Tidal listen' => ['https://listen.tidal.com/track/172804280', 'tidal'],
    'Deezer' => ['https://www.deezer.com/track/1069843552', 'deezer'],
    'YouTube' => ['https://www.youtube.com/watch?v=yG4VfxlXbIc', 'youtube'],
    'youtu.be' => ['https://youtu.be/yG4VfxlXbIc', 'youtube'],
    'YouTube mobile' => ['https://m.youtube.com/watch?v=yG4VfxlXbIc', 'youtube'],
    'Yandex' => ['https://music.yandex.ru/track/70780629', 'yandex'],
    'Anghami' => ['https://play.anghami.com/song/1447428?refer=linktree', 'anghami'],
    'Boomplay' => ['https://www.boomplay.com/songs/44031875', 'boomplay'],
    'Pandora app link' => ['https://pandora.app.link/abc', 'pandora'],
    'Apple Music' => ['https://music.apple.com/de/album/x/123', 'applemusic'],
    'iTunes' => ['https://itunes.apple.com/de/album/x/id123', 'applemusic'],
    'Amazon Music' => ['https://music.amazon.de/albums/B0', 'amazonmusic'],
    'Amazon shop' => ['https://www.amazon.de/dp/B0', 'amazonmusic'],
    'SoundCloud' => ['https://soundcloud.com/anders/song', 'soundcloud'],
    'SoundCloud short' => ['https://on.soundcloud.com/abc', 'soundcloud'],
    'Bandcamp artist subdomain' => ['https://anders.bandcamp.com/track/song', 'bandcamp'],
    'Napster' => ['https://web.napster.com/track/tra.1', 'napster'],
    'upper case host' => ['HTTPS://OPEN.SPOTIFY.COM/track/1w0r0NDXEByTCr7wa5HjNK', 'spotify'],
    // not a platform
    'apple.com itself' => ['https://www.apple.com/de/', 'other'],
    'podcasts.apple.com' => ['https://podcasts.apple.com/de/podcast/x', 'other'],
    'unknown host' => ['https://example.com/spotify.com/track/1', 'other'],
    'lookalike domain' => ['https://notspotify.com/track/1', 'other'],
    'platform in the path only' => ['https://evil.test/?u=https://open.spotify.com', 'other'],
    'javascript' => ['javascript:alert(1)', 'other'],
    'no scheme' => ['open.spotify.com/track/1', 'other'],
    'empty' => ['', 'other'],
]);

it('detects the platform from the host alone', function (string $url, string $platform) {
    expect(app(Platforms::class)->detect($url))->toBe($platform);
})->with('urls');

it('takes extra hosts from config, and the more specific host still wins', function () {
    $platforms = new Platforms(['audiomack' => ['audiomack.com'], 'deezer' => ['deezer.link']]);

    expect($platforms->detect('https://audiomack.com/anders/song/x'))->toBe('audiomack')
        ->and($platforms->detect('https://deezer.link/x'))->toBe('deezer')
        ->and($platforms->detect('https://music.youtube.com/x'))->toBe('youtubemusic');
});

it('reads the Spotify track ID from every form it comes in', function (?string $value, ?string $id) {
    expect(Track::spotifyIdFrom($value))->toBe($id);
})->with([
    ['1w0r0NDXEByTCr7wa5HjNK', '1w0r0NDXEByTCr7wa5HjNK'],
    ['spotify:track:1w0r0NDXEByTCr7wa5HjNK', '1w0r0NDXEByTCr7wa5HjNK'],
    ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK?si=abc', '1w0r0NDXEByTCr7wa5HjNK'],
    ['https://open.spotify.com/intl-de/track/1w0r0NDXEByTCr7wa5HjNK', '1w0r0NDXEByTCr7wa5HjNK'],
    ['https://open.spotify.com/album/1w0r0NDXEByTCr7wa5HjNK', null],
    ['../../etc/passwd', null],
    [null, null],
]);

it('accepts only well-formed ISRCs', function () {
    expect(Track::normaliseIsrc('de-a91-23-00001'))->toBe('DEA912300001')
        ->and(Track::normaliseIsrc('DEA91230000'))->toBeNull()
        ->and(Track::normaliseIsrc('DEA912300001/../x'))->toBeNull();
});
