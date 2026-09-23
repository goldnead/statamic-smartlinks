<?php

use Goldnead\Smartlinks\Facades\Smartlinks;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\Antlers;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

const UA_SEG = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0';

function remountRoutes(): void
{
    Route::setRoutes(new RouteCollection);
    Route::middleware('web')->group(__DIR__.'/../../routes/web.php');
    Route::getRoutes()->refreshNameLookups();
}

beforeEach(function () {
    // ANDERS: the single "Alles wird gut" and its song share the slug.
    config(['smartlinks.release_collections' => ['releases']]);
    remountRoutes();
    Collection::make('releases')->save();

    $this->song = $this->makeSong('Alles wird gut', ['https://www.deezer.com/track/1069843552']);
    $this->release = Entry::make()->collection('releases')->slug('alles-wird-gut')
        ->data(['title' => 'Alles wird gut (Single)', 'streaming_links' => [['url' => 'https://www.deezer.com/album/171123492']]]);
    $this->release->save();
});

it('keeps the song at /hoeren/{slug} and puts the release at /hoeren/release/{slug}', function () {
    $this->get('/hoeren/alles-wird-gut')->assertOk()->assertSee('Alles wird gut')->assertDontSee('(Single)');
    $this->get('/hoeren/release/alles-wird-gut')->assertOk()->assertSee('Alles wird gut (Single)');

    $this->withHeader('User-Agent', UA_SEG)->get('/hoeren/alles-wird-gut/deezer')->assertRedirect('https://www.deezer.com/track/1069843552');
    $this->withHeader('User-Agent', UA_SEG)->get('/hoeren/release/alles-wird-gut/deezer')->assertRedirect('https://www.deezer.com/album/171123492');
});

it('builds each entry\'s URLs on its own route, in the facade and the tags', function () {
    expect(Smartlinks::landingUrl($this->song))->toBe('http://localhost/hoeren/alles-wird-gut')
        ->and(Smartlinks::landingUrl($this->release))->toBe('http://localhost/hoeren/release/alles-wird-gut')
        ->and(Smartlinks::clickUrl($this->release, 'deezer'))->toBe('http://localhost/hoeren/release/alles-wird-gut/deezer')
        ->and((string) Antlers::parse('{{ smartlinks:page entry="'.$this->release->id().'" }}', [], true))->toBe('http://localhost/hoeren/release/alles-wird-gut')
        ->and((string) Antlers::parse('{{ smartlinks:links entry="'.$this->release->id().'" }}{{ click_url }}{{ /smartlinks:links }}', [], true))
        ->toBe('http://localhost/hoeren/release/alles-wird-gut/deezer');
});

it('does not serve a release under the song route or a song under the release route', function () {
    $this->release->slug('nur-als-release')->save();
    $this->song->slug('nur-als-song')->save();

    $this->get('/hoeren/nur-als-release')->assertNotFound();
    $this->get('/hoeren/release/nur-als-song')->assertNotFound();
});

it('takes the segment per collection from config, and an empty one mounts at the prefix', function () {
    config(['smartlinks.routes.segments' => ['releases' => 'album', 'songs' => 'song']]);
    remountRoutes();

    $this->get('/hoeren/album/alles-wird-gut')->assertOk()->assertSee('(Single)');
    $this->get('/hoeren/song/alles-wird-gut')->assertOk()->assertDontSee('(Single)');
    expect(Smartlinks::landingUrl($this->song))->toBe('http://localhost/hoeren/song/alles-wird-gut');

    config(['smartlinks.routes.segments' => ['releases' => '']]);
    remountRoutes();
    // Both at the prefix: the collection listed first wins, every time.
    $this->get('/hoeren/alles-wird-gut')->assertSee('Alles wird gut')->assertDontSee('(Single)');
});
