<?php

use Goldnead\Smartlinks\Contracts\HostResolver;
use Goldnead\Smartlinks\LinkChecker;
use Goldnead\Smartlinks\LinkStatus;
use Goldnead\Smartlinks\Support\IpGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * DNS as the test says: every host is public unless listed.
 *
 * @param  array<string, list<string>>  $hosts
 */
function fakeDns(array $hosts = []): void
{
    app()->instance(HostResolver::class, new class($hosts) implements HostResolver
    {
        public function __construct(private array $hosts) {}

        public function resolve(string $host): array
        {
            return $this->hosts[$host] ?? ['93.184.216.34'];
        }
    });
    app()->forgetInstance(LinkChecker::class);
}

beforeEach(function () {
    config(['smartlinks.check.per_host_ms' => 0]);
    fakeDns();
});

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
        'refused.test/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
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
    // A connect error is the network's word, not the link's: never dead.
    ['https://refused.test/a', LinkStatus::UNKNOWN],
    ['https://slow.test/a', LinkStatus::UNKNOWN],
]);

it('counts a host that does not resolve as unknown, not dead', function () {
    fakeDns(['nxdomain.test' => []]);
    Http::fake();

    expect(app(LinkChecker::class)->check('https://nxdomain.test/a')['status'])->toBe(LinkStatus::UNKNOWN);
    Http::assertNothingSent();
});

it('never requests a private, loopback, link-local or metadata address', function (string $url, array $dns) {
    fakeDns($dns);
    Http::fake(['*' => Http::response('', 200)]);

    expect(app(LinkChecker::class)->check($url)['status'])->toBe(LinkStatus::UNKNOWN);
    Http::assertNothingSent();
})->with([
    'metadata by name' => ['https://meta.test/latest', ['meta.test' => ['169.254.169.254']]],
    'private by name' => ['https://intranet.test/', ['intranet.test' => ['10.0.0.5']]],
    'one of several records private' => ['https://mixed.test/', ['mixed.test' => ['93.184.216.34', '192.168.1.1']]],
    'loopback v6' => ['https://v6.test/', ['v6.test' => ['::1']]],
    'unique local v6' => ['https://ula.test/', ['ula.test' => ['fd00:ec2::254']]],
    'mapped v4 in v6' => ['https://mapped.test/', ['mapped.test' => ['::ffff:127.0.0.1']]],
    'literal loopback' => ['http://127.0.0.1/', []],
    'literal metadata' => ['http://169.254.169.254/latest/meta-data/', []],
    'literal v6' => ['http://[::1]/', []],
]);

it('validates every redirect hop itself and stops at a private one', function () {
    fakeDns(['intranet.test' => ['10.0.0.5']]);
    Http::fake([
        'public.test/*' => Http::response('', 302, ['Location' => 'http://intranet.test/admin']),
        '*' => Http::response('', 200),
    ]);

    expect(app(LinkChecker::class)->check('https://public.test/a')['status'])->toBe(LinkStatus::UNKNOWN);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'intranet.test'));
});

it('follows at most five redirects, relative ones too', function () {
    Http::fake([
        'hop.test/1' => Http::response('', 301, ['Location' => '/2']),
        'hop.test/2' => Http::response('', 302, ['Location' => 'https://ok.test/done']),
        'ok.test/*' => Http::response('', 200),
        'loop.test/*' => Http::response('', 302, ['Location' => 'https://loop.test/again']),
    ]);

    expect(app(LinkChecker::class)->check('https://hop.test/1')['status'])->toBe(LinkStatus::OK)
        ->and(app(LinkChecker::class)->check('https://loop.test/start')['status'])->toBe(LinkStatus::UNKNOWN);
});

it('knows the ranges that must never be fetched', function (string $ip, bool $allowed) {
    expect(IpGuard::isPublic($ip))->toBe($allowed);
})->with([
    ['93.184.216.34', true], ['2a00:1450:4001::1', true],
    ['127.0.0.1', false], ['10.1.2.3', false], ['172.16.0.1', false], ['192.168.0.1', false],
    ['169.254.169.254', false], ['100.64.0.1', false], ['0.0.0.0', false], ['224.0.0.1', false],
    ['198.18.0.1', false], ['192.0.2.1', false], ['255.255.255.255', false],
    ['::1', false], ['::', false], ['fc00::1', false], ['fd00:ec2::254', false], ['fe80::1', false],
    ['ff02::1', false], ['::ffff:10.0.0.1', false], ['64:ff9b::a00:1', false], ['2001:db8::1', false],
    ['not-an-ip', false],
]);

it('spaces requests to the same host', function () {
    config(['smartlinks.check.per_host_ms' => 1000]);
    Sleep::fake();
    Http::fake(['*' => Http::response('', 200)]);

    $checker = app(LinkChecker::class);
    $checker->check('https://a.test/1');
    $checker->check('https://b.test/1');
    $checker->check('https://a.test/2');

    Sleep::assertSleptTimes(1);
});

it('confirms a dead link only on the second dead check in a row, and only then hides it', function () {
    $song = $this->makeSong('Viel Lärm', [
        'https://music.apple.com/de/album/x/1370900000?i=1370900001',
        'https://music.apple.com/de/album/x/1840900000?i=1840900001',
        'https://www.deezer.com/track/1',
    ]);
    Http::fake([
        'music.apple.com/de/album/x/1370900000*' => Http::response('', 404),
        '*' => Http::response('', 200),
    ]);

    $this->artisan('smartlinks:check', ['--dry-run' => true])->expectsOutputToContain('2 ok, 1 dead, 0 unknown. Dry run: nothing recorded.')->assertSuccessful();
    expect(DB::table(LinkStatus::TABLE)->count())->toBe(0);

    // First dead answer: suspected, still shown.
    $this->artisan('smartlinks:check')->assertSuccessful();
    expect(app(LinkStatus::class)->deadCounts())->toBe([]);
    $this->withHeader('User-Agent', 'Mozilla/5.0 Firefox/130')->get('/hoeren/viel-larm/applemusic')
        ->assertRedirect('https://music.apple.com/de/album/x/1370900000?i=1370900001');

    // Second in a row: confirmed, hidden, the other Apple link takes over.
    $this->artisan('smartlinks:check')->assertSuccessful();
    expect(app(LinkStatus::class)->deadCounts())->toBe([(string) $song->id() => 1]);
    $this->withHeader('User-Agent', 'Mozilla/5.0 Firefox/130')->get('/hoeren/viel-larm/applemusic')
        ->assertRedirect('https://music.apple.com/de/album/x/1840900000?i=1840900001');

    config(['smartlinks.check.hide_dead' => false]);
    $this->withHeader('User-Agent', 'Mozilla/5.0 Firefox/130')->get('/hoeren/viel-larm/applemusic')
        ->assertRedirect('https://music.apple.com/de/album/x/1370900000?i=1370900001');
});

it('resets the streak when a link answers again, and an unknown result neither confirms nor resets', function () {
    $status = app(LinkStatus::class);
    $status->record('e', 'https://x.test/a', LinkStatus::DEAD, 404);
    $status->record('e', 'https://x.test/a', LinkStatus::UNKNOWN, 503);
    expect($status->dead('e'))->toBe([]);

    $status->record('e', 'https://x.test/a', LinkStatus::DEAD, 404);
    expect($status->dead('e'))->toBe(['https://x.test/a']);

    $status->record('e', 'https://x.test/a', LinkStatus::OK, 200);
    $status->record('e', 'https://x.test/a', LinkStatus::DEAD, 404);
    expect($status->dead('e'))->toBe([]);
});
