<?php

use Goldnead\Smartlinks\Facades\Smartlinks;
use Goldnead\Smartlinks\Fieldtypes\SmartlinkUrl;
use Goldnead\Smartlinks\Platforms;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Antlers;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

const UA = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0';

it('registers no front-end routes when the switch is off', function () {
    config(['smartlinks.routes.enabled' => false]);
    Route::setRoutes(new RouteCollection);

    require __DIR__.'/../../routes/web.php';
    Route::getRoutes()->refreshNameLookups();

    expect(Route::has('smartlinks.show'))->toBeFalse()
        ->and(Route::has('smartlinks.go'))->toBeFalse();
});

it('closes routes a cache kept alive once the switch is off, and hides the click URLs', function () {
    $song = $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);
    config(['smartlinks.routes.enabled' => false]);

    $this->get('/hoeren/alles-wird-gut')->assertNotFound();
    $this->withHeader('User-Agent', UA)->get('/hoeren/alles-wird-gut/spotify')->assertNotFound();

    expect(Smartlinks::links($song)[0]->clickUrl)->toBeNull();
});

it('mounts under the configured prefix', function () {
    config(['smartlinks.routes.prefix' => 'listen']);
    Route::setRoutes(new RouteCollection);
    Route::middleware('web')->group(__DIR__.'/../../routes/web.php');
    Route::getRoutes()->refreshNameLookups();
    $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);

    $this->withHeader('User-Agent', UA)->get('/listen/alles-wird-gut/spotify')->assertStatus(302);
});

function cpUser(array $permissions): Statamic\Contracts\Auth\User
{
    $role = Role::make('smartlinks-test-'.md5(implode(',', $permissions)))->permissions(['access cp', ...$permissions]);
    $role->save();

    $user = User::make()->email(uniqid().'@example.com')->assignRole($role);
    $user->save();

    return $user;
}

it('shows clicks per song and platform over the last 30 days', function () {
    $a = $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK', 'https://www.deezer.com/track/1']);
    $this->makeSong('Bei dir', ['https://listen.tidal.com/track/1']);

    $this->travelTo(now()->subDays(40));
    $this->withHeader('User-Agent', UA)->get('/hoeren/bei-dir/tidal');
    $this->travelBack();
    $this->withHeader('User-Agent', UA)->get('/hoeren/alles-wird-gut/spotify');
    $this->withHeader('User-Agent', UA)->get('/hoeren/alles-wird-gut/spotify');
    $this->withHeader('User-Agent', UA)->get('/hoeren/alles-wird-gut/deezer');

    $this->actingAs(cpUser(['view smartlinks']))
        ->get('/cp/smartlinks')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('smartlinks::Smartlinks/Index')
            ->where('days', 30)
            ->has('rows', 2)
            ->where('rows.0.id', (string) $a->id())
            ->where('rows.0.total', 3)
            ->where('rows.0.platform_spotify', 2)
            ->where('rows.0.platform_deezer', 1)
            ->where('rows.0.links', 2)
            ->where('rows.1.title', 'Bei dir')
            ->where('rows.1.total', 0)
            ->has('initialColumns', 5)
            ->where('initialColumns.3.field', 'platform_spotify')
            ->where('initialColumns.3.label', 'Spotify'));
});

it('refuses the page without view smartlinks', function () {
    $response = $this->actingAs(cpUser([]))->get('/cp/smartlinks');

    // Core turns a failed `can:` in the CP into a redirect with an error toast.
    expect($response->status())->toBeIn([302, 403])
        ->and($response->headers->get('X-Inertia'))->toBeNull();
});

it('registers the permission and the nav item', function () {
    expect(Permission::boot()->get('view smartlinks'))->not->toBeNull()
        ->and($this->navCallbacks)->toHaveCount(1);
});

it('renders the links tag with platform, url, label, icon and click url', function () {
    $song = $this->makeSong('Alles wird gut', [
        ['platform' => 'spotify', 'url' => 'https://listen.tidal.com/track/1'],
        'https://geo.music.apple.com/de/album/x/1',
    ]);

    $out = (string) Antlers::parse(
        '{{ smartlinks:links :entry="song" }}[{{ platform }}|{{ label }}|{{ icon }}|{{ url }}|{{ click_url }}]{{ /smartlinks:links }}',
        ['song' => $song->id()],
        true
    );

    expect($out)->toBe(
        '[tidal|Tidal|tidal|https://listen.tidal.com/track/1|http://localhost/hoeren/alles-wird-gut/tidal]'
        .'[applemusic|Apple Music|applemusic|https://geo.music.apple.com/de/album/x/1|http://localhost/hoeren/alles-wird-gut/applemusic]'
    );
});

it('renders the url tag for one platform, from the context entry too', function () {
    $song = $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);

    expect((string) Antlers::parse('{{ smartlinks:url entry="alles-wird-gut" platform="spotify" }}', [], true))
        ->toBe('https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK')
        ->and((string) Antlers::parse('{{ smartlinks:url platform="spotify" }}', ['id' => $song->id()], true))
        ->toBe('https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK')
        ->and((string) Antlers::parse('{{ smartlinks:url platform="deezer" entry="alles-wird-gut" }}', [], true))->toBe('')
        ->and((string) Antlers::parse('{{ smartlinks:page entry="alles-wird-gut" }}', [], true))->toBe('http://localhost/hoeren/alles-wird-gut');
});

it('hands the fieldtype the same host table PHP decides with', function () {
    $meta = (new SmartlinkUrl)->preload();

    expect($meta['hosts']['music.youtube.com'])->toBe('youtubemusic')
        ->and(array_search('music.youtube.com', array_keys($meta['hosts'])))->toBeLessThan(array_search('youtube.com', array_keys($meta['hosts'])))
        ->and($meta['labels']['other'])->toBe('Other')
        ->and((new SmartlinkUrl)->process('  https://x.test  '))->toBe('https://x.test');
});

it('speaks German', function () {
    app()->setLocale('de');

    expect(__('smartlinks::cp.permission_view'))->toBe('Smart-Link-Klicks ansehen')
        ->and(app(Platforms::class)->label('other'))->toBe('Andere');
});
