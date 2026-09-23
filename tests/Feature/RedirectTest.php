<?php

use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

const BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15';

function clickRows(): array
{
    return DB::table(Smartlinks::TABLE)->orderBy('platform')->get()
        ->map(fn ($r) => [$r->entry_id, $r->platform, (int) $r->clicks])->all();
}

it('redirects to the stored URL and counts the click', function () {
    $song = $this->makeSong('Alles wird gut', [
        'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK',
        'https://tidal.com/track/172804280',
    ]);

    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/alles-wird-gut/tidal')
        ->assertStatus(302)
        ->assertRedirect('https://tidal.com/track/172804280');
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/alles-wird-gut/tidal')->assertStatus(302);

    expect(clickRows())->toBe([[(string) $song->id(), 'tidal', 2]]);
});

it('ignores a hand-typed platform label and goes by the URL', function () {
    // The ANDERS failure: a Tidal link labelled Spotify.
    $this->makeSong('Bei dir', [['platform' => 'spotify', 'url' => 'https://tidal.com/track/1']]);

    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/bei-dir/spotify')->assertNotFound();
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/bei-dir/tidal')->assertRedirect('https://tidal.com/track/1');
});

it('is a 404 for a tampered platform or slug, and never redirects off-list', function (string $path) {
    $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);

    $response = $this->withHeader('User-Agent', BROWSER)->get($path);

    $response->assertNotFound();
    expect($response->headers->get('Location'))->toBeNull()
        ->and(clickRows())->toBe([]);
})->with([
    'unknown platform' => ['/hoeren/alles-wird-gut/deezer'],
    'made-up platform' => ['/hoeren/alles-wird-gut/evil'],
    'unknown slug' => ['/hoeren/gibt-es-nicht/spotify'],
    'URL as platform' => ['/hoeren/alles-wird-gut/https:%2F%2Fevil.test'],
    'URL in query' => ['/hoeren/alles-wird-gut/other?url=https://evil.test'],
    'path traversal' => ['/hoeren/..%2F..%2Fetc/spotify'],
]);

it('never redirects to a stored value that is not an http(s) URL', function () {
    $this->makeSong('Kaputt', ['javascript:alert(1)', 'data:text/html,x']);

    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/kaputt/other')->assertNotFound();
});

it('does not serve songs of other collections or unpublished songs', function () {
    Collection::make('pages')->save();
    Entry::make()->collection('pages')->slug('seite')
        ->data(['title' => 'Seite', 'streaming_links' => [['url' => 'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']]])->save();
    $this->makeSong('Entwurf', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK'], published: false);

    $this->get('/hoeren/seite/spotify')->assertNotFound();
    $this->get('/hoeren/entwurf/spotify')->assertNotFound();
    $this->get('/hoeren/entwurf')->assertNotFound();
});

it('redirects bots and link previews without counting them', function (?string $userAgent) {
    $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);

    $this->withHeader('User-Agent', (string) $userAgent)->get('/hoeren/alles-wird-gut/spotify')->assertStatus(302);

    expect(clickRows())->toBe([]);
})->with([
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
    'WhatsApp preview' => ['WhatsApp/2.23.20.0'],
    'Facebook' => ['facebookexternalhit/1.1'],
    'curl' => ['curl/8.4.0'],
    'empty' => [''],
]);

it('does not count HEAD requests', function () {
    $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);

    $this->withHeader('User-Agent', BROWSER)->call('HEAD', '/hoeren/alles-wird-gut/spotify')->assertStatus(302);

    expect(clickRows())->toBe([]);
});

it('does not count browser prefetches', function (string $header, string $value) {
    $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);

    $this->withHeaders(['User-Agent' => BROWSER, $header => $value])->get('/hoeren/alles-wird-gut/spotify')->assertStatus(302);

    expect(clickRows())->toBe([]);
})->with([
    'Sec-Purpose' => ['Sec-Purpose', 'prefetch'],
    'Sec-Purpose prerender' => ['Sec-Purpose', 'prefetch;prerender'],
    'Purpose' => ['Purpose', 'prefetch'],
    'X-Moz' => ['X-Moz', 'prefetch'],
]);

it('never throttles a venue full of listeners, only caps counting per IP, song and platform', function () {
    config(['smartlinks.clicks.per_minute' => 5]);
    $song = $this->makeSong('Alles wird gut', [
        'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK',
        'https://www.deezer.com/track/1',
    ]);

    // One shared venue IP, a QR code on the stage.
    foreach (range(1, 80) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', BROWSER)->get('/hoeren/alles-wird-gut/spotify')->assertStatus(302);
    }
    foreach (range(1, 80) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('/hoeren/alles-wird-gut')->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', BROWSER)->get('/hoeren/alles-wird-gut/deezer');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])
        ->withHeader('User-Agent', BROWSER)->get('/hoeren/alles-wird-gut/spotify');

    expect(clickRows())->toBe([
        [(string) $song->id(), 'deezer', 1],
        [(string) $song->id(), 'spotify', 6],
    ]);

    $this->travel(61)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', BROWSER)->get('/hoeren/alles-wird-gut/spotify');
    expect(clickRows()[1][2])->toBe(7);
});

it('does not redirect to a stored URL carrying userinfo', function () {
    $this->makeSong('Trick', ['https://open.spotify.com@evil.test/track/1']);

    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/trick/other')->assertNotFound();
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/trick/spotify')->assertNotFound();
});

it('counts per song, platform and day in one row each, storing nothing about the listener', function () {
    $a = $this->makeSong('A', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK', 'https://www.deezer.com/track/1']);
    $b = $this->makeSong('B', ['https://open.spotify.com/track/2w0r0NDXEByTCr7wa5HjNK']);

    $this->travelTo('2026-09-20 12:00');
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/a/spotify');
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/a/spotify');
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/a/deezer');
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/b/spotify');
    $this->travelTo('2026-09-21 12:00');
    $this->withHeader('User-Agent', BROWSER)->get('/hoeren/a/spotify');

    expect(DB::table(Smartlinks::TABLE)->count())->toBe(4)
        ->and(DB::table(Smartlinks::TABLE)->where('entry_id', $a->id())->where('platform', 'spotify')->where('day', '2026-09-20')->value('clicks'))->toBe(2)
        ->and(array_keys((array) DB::table(Smartlinks::TABLE)->first()))->toBe(['id', 'entry_id', 'platform', 'day', 'clicks'])
        ->and(app(Smartlinks::class)->clicks(30))->toEqual([
            (string) $a->id() => ['deezer' => 1, 'spotify' => 3],
            (string) $b->id() => ['spotify' => 1],
        ]);

    $this->travelBack();
});

it('shows the landing page with one button per platform, first link of a platform wins', function () {
    $this->makeSong('Alles wird gut', [
        'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK',
        'https://open.spotify.com/album/0000000000000000000000',
        'https://music.youtube.com/watch?v=yG4VfxlXbIc',
        'javascript:alert(1)',
    ]);

    $this->get('/hoeren/alles-wird-gut')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertSeeInOrder(['Alles wird gut', 'Spotify', 'YouTube Music'])
        ->assertSee('/hoeren/alles-wird-gut/spotify', false)
        ->assertSee('/hoeren/alles-wird-gut/youtubemusic', false)
        ->assertDontSee('javascript:', false);
});
