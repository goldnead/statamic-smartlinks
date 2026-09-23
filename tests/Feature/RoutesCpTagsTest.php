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
    $this->makeSong('Bei dir', ['https://tidal.com/track/1']);

    $this->travelTo(now()->subDays(40));
    $this->withHeader('User-Agent', UA)->get('/hoeren/bei-dir/tidal');
    $this->travelBack();
    $this->withHeader('User-Agent', UA)->get('/hoeren/alles-wird-gut/spotify');
    $this->withHeader('User-Agent', UA)->get('/hoeren/alles-wird-gut/spotify');
    $this->withHeader('User-Agent', UA)->get('/hoeren/alles-wird-gut/deezer');

    $user = cpUser(['view smartlinks']);

    $this->actingAs($user)
        ->get('/cp/smartlinks')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('smartlinks::Smartlinks/Index')
            ->where('days', 30)
            ->where('hasSongs', true)
            ->where('listingUrl', cp_route('smartlinks.listing'))
            // title, total, links, dead, suggestions, then the platforms
            ->has('initialColumns', 7)
            ->where('initialColumns.5.field', 'platform_spotify')
            ->where('initialColumns.5.label', 'Spotify'));

    $this->actingAs($user)->getJson('/cp/smartlinks/listing')
        ->assertOk()
        ->assertJsonPath('data.0.id', (string) $a->id())
        ->assertJsonPath('data.0.total', 3)
        ->assertJsonPath('data.0.platform_spotify', 2)
        ->assertJsonPath('data.0.platform_deezer', 1)
        ->assertJsonPath('data.0.links', 2)
        ->assertJsonPath('data.1.title', 'Bei dir')
        ->assertJsonPath('data.1.total', 0)
        // Core's paginator meta: the "1–2 of 2" footer.
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 2)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonCount(7, 'meta.columns');
});

it('keeps few columns visible by default, like core, so a phone fits title and row menu', function () {
    $this->makeSong('A', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK', 'https://www.deezer.com/track/1', 'https://tidal.com/track/1']);
    foreach (['spotify', 'spotify', 'deezer', 'tidal'] as $platform) {
        $this->withHeader('User-Agent', UA)->get('/hoeren/a/'.$platform);
    }

    $columns = collect($this->actingAs(cpUser(['view smartlinks']))->getJson('/cp/smartlinks/listing')->json('meta.columns'));

    expect($columns->where('visible', true)->pluck('field')->all())->toBe(['title', 'total', 'platform_spotify'])
        ->and($columns->pluck('field')->all())->toContain('links', 'platform_deezer', 'platform_tidal');
});

it('searches, sorts and pages on the server', function () {
    foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
        $this->makeSong($title, ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);
    }
    $user = cpUser(['view smartlinks']);

    $this->actingAs($user)->getJson('/cp/smartlinks/listing?search=amm')
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Gamma');

    $this->actingAs($user)->getJson('/cp/smartlinks/listing?sort=title&order=desc&perPage=2&page=2')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Alpha')
        ->assertJsonPath('meta.from', 3)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);

    $this->actingAs($user)->getJson('/cp/smartlinks/listing?columns=title,links')
        ->assertJsonPath('meta.columns.2.field', 'links')
        ->assertJsonPath('meta.columns.2.visible', true)
        ->assertJsonPath('meta.columns.1.visible', false);
});

it('refuses the page without view smartlinks', function () {
    $response = $this->actingAs(cpUser([]))->get('/cp/smartlinks');

    // Core turns a failed `can:` in the CP into a redirect with an error toast.
    expect($response->status())->toBeIn([302, 403])
        ->and($response->headers->get('X-Inertia'))->toBeNull();

    $this->makeSong('A', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);
    $listing = $this->actingAs(cpUser([]))->getJson('/cp/smartlinks/listing');
    expect($listing->status())->toBeIn([302, 403])
        ->and($listing->json('data'))->toBeNull();
});

it('registers the permission and the nav item', function () {
    expect(Permission::boot()->get('view smartlinks'))->not->toBeNull()
        ->and($this->navCallbacks)->toHaveCount(1);
});

it('renders the links tag with platform, url, label, icon and click url', function () {
    $song = $this->makeSong('Alles wird gut', [
        ['platform' => 'spotify', 'url' => 'https://tidal.com/track/1'],
        'https://geo.music.apple.com/de/album/x/1',
    ]);

    $out = (string) Antlers::parse(
        '{{ smartlinks:links :entry="song" }}[{{ platform }}|{{ label }}|{{ icon }}|{{ url }}|{{ click_url }}]{{ /smartlinks:links }}',
        ['song' => $song->id()],
        true
    );

    // Apple Music before Tidal: priority, not stored order.
    expect($out)->toBe(
        '[applemusic|Apple Music|applemusic|https://music.apple.com/de/album/x/1|http://localhost/hoeren/alles-wird-gut/applemusic]'
        .'[tidal|Tidal|tidal|https://tidal.com/track/1|http://localhost/hoeren/alles-wird-gut/tidal]'
    );
});

it('orders links by the configured priority, then alphabetically, other last', function () {
    $song = $this->makeSong('Reihenfolge', [
        'https://example.com/merch',
        'https://www.boomplay.com/songs/1',
        'https://www.amazon.de/dp/B0',
        'https://tidal.com/track/1',
        'https://www.anghami.com/song/1',
        'https://music.youtube.com/watch?v=yG4VfxlXbIc',
        'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK',
    ]);

    $order = fn () => array_map(fn ($l) => $l->platform, Smartlinks::links($song));

    expect($order())->toBe(['spotify', 'youtubemusic', 'tidal', 'amazon', 'anghami', 'boomplay', 'other']);

    // Handles with underscores work as the config reads naturally.
    config(['smartlinks.priority' => ['amazon', 'youtube_music']]);
    expect($order())->toBe(['amazon', 'youtubemusic', 'anghami', 'boomplay', 'spotify', 'tidal', 'other']);

    $this->get('/hoeren/reihenfolge')->assertSeeInOrder(['Amazon', 'YouTube Music', 'Anghami', 'Boomplay', 'Spotify', 'Tidal']);
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
