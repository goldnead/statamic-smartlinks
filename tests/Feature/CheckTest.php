<?php

use Goldnead\Smartlinks\LinkChecker;
use Goldnead\Smartlinks\LinkStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => config(['smartlinks.check.per_host_ms' => 0]));

it('tells ok, dead and unknown apart, trying GET when HEAD is refused', function (string $url, string $status) {
    Http::fake([
        'ok.test/*' => Http::response('', 200),
        'redirect.test/*' => Http::response('', 301, ['Location' => 'https://ok.test/x']),
        'gone.test/*' => Http::response('', 404),
        'removed.test/*' => Http::response('', 410),
        // HEAD refused, GET fine: a shop, not a dead link.
        'nohead.test/*' => fn (Request $r) => Http::response('', $r->method() === 'HEAD' ? 405 : 200),
        'wall.test/*' => Http::response('', 403),
        'busy.test/*' => Http::response('', 429),
        'broken.test/*' => Http::response('', 503),
        'nxdomain.test/*' => fn () => throw new ConnectionException('cURL error 6: Could not resolve host: nxdomain.test'),
        'slow.test/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
    ]);

    expect(app(LinkChecker::class)->check($url)['status'])->toBe($status);
})->with([
    ['https://ok.test/a', LinkStatus::OK],
    ['https://redirect.test/a', LinkStatus::OK],
    ['https://gone.test/a', LinkStatus::DEAD],
    ['https://removed.test/a', LinkStatus::DEAD],
    ['https://nohead.test/a', LinkStatus::OK],
    ['https://wall.test/a', LinkStatus::UNKNOWN],
    ['https://busy.test/a', LinkStatus::UNKNOWN],
    ['https://broken.test/a', LinkStatus::UNKNOWN],
    ['https://nxdomain.test/a', LinkStatus::DEAD],
    ['https://slow.test/a', LinkStatus::UNKNOWN],
]);

it('spaces requests to the same host', function () {
    config(['smartlinks.check.per_host_ms' => 1000]);
    Sleep::fake();
    Http::fake(['*' => Http::response('', 200)]);

    $checker = new LinkChecker;
    $checker->check('https://a.test/1');
    $checker->check('https://b.test/1');
    $checker->check('https://a.test/2');

    Sleep::assertSleptTimes(1);
});

it('records dead links, hides them on the landing page, and a second link of the platform takes over', function () {
    $song = $this->makeSong('Viel Lärm', [
        'https://music.apple.com/de/album/x/1370900000?i=1370900001',
        'https://music.apple.com/de/album/x/1840900000?i=1840900001',
        'https://play.napster.com/track/tra.1',
        'https://www.deezer.com/track/1',
    ]);
    Http::fake([
        'music.apple.com/de/album/x/1370900000*' => Http::response('', 404),
        'play.napster.com/*' => fn () => throw new ConnectionException('Could not resolve host: play.napster.com'),
        '*' => Http::response('', 200),
    ]);

    $this->artisan('smartlinks:check', ['--dry-run' => true])->expectsOutputToContain('2 ok, 2 dead, 0 unknown. Dry run: nothing recorded.')->assertSuccessful();
    expect(DB::table(LinkStatus::TABLE)->count())->toBe(0);

    $this->artisan('smartlinks:check')->assertSuccessful();
    expect(DB::table(LinkStatus::TABLE)->where('status', LinkStatus::DEAD)->count())->toBe(2);

    $this->get('/hoeren/viel-larm')
        ->assertSee('/hoeren/viel-larm/applemusic', false)
        ->assertDontSee('Napster');
    $this->withHeader('User-Agent', 'Mozilla/5.0 Firefox/130')->get('/hoeren/viel-larm/applemusic')
        ->assertRedirect('https://music.apple.com/de/album/x/1840900000?i=1840900001');

    config(['smartlinks.check.hide_dead' => false]);
    $this->get('/hoeren/viel-larm')->assertSee('Napster');

    expect(app(LinkStatus::class)->deadCounts())->toBe([(string) $song->id() => 2]);
});
