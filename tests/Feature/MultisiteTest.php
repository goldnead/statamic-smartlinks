<?php

use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

beforeEach(function () {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'de' => ['name' => 'Deutsch', 'url' => 'http://localhost/', 'locale' => 'de_DE'],
        'en' => ['name' => 'English', 'url' => 'http://localhost/en/', 'locale' => 'en_US'],
    ]);
    Collection::find('songs')->sites(['de', 'en'])->save();

    $this->origin = $this->makeSong('Alles wird gut', ['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);
    // A localisation that only translates the title: the links are inherited.
    $this->translation = $this->origin->makeLocalization('en')->slug('alles-wird-gut')->data(['title' => 'All will be well']);
    $this->translation->save();
});

it('reads the links a localisation inherits from its origin', function () {
    $links = app(Smartlinks::class)->links(Entry::find($this->translation->id()));

    expect($links)->toHaveCount(1)
        ->and($links[0]->platform)->toBe('spotify');
});

it('adds resolved links to the origin, so the localisation keeps inheriting', function () {
    $this->origin->set('spotify_id', '1w0r0NDXEByTCr7wa5HjNK')->save();
    Http::fake(['api.deezer.com/*' => Http::response(['link' => 'https://www.deezer.com/track/9'])]);
    config(['smartlinks.isrc_field' => 'isrc']);
    $this->origin->set('isrc', 'DEA912300001')->save();

    $this->artisan('smartlinks:resolve', ['entry' => $this->translation->id()])->assertSuccessful();

    expect(Entry::find($this->translation->id())->has('streaming_links'))->toBeFalse()
        ->and(Entry::find($this->origin->id())->get('streaming_links'))->toHaveCount(2)
        ->and(app(Smartlinks::class)->links(Entry::find($this->translation->id())))->toHaveCount(2);
});

it('finds the entry of the current site by slug', function () {
    Site::setCurrent('en');
    expect(app(Smartlinks::class)->findEntry('alles-wird-gut')?->id())->toBe($this->translation->id());

    Site::setCurrent('de');
    expect(app(Smartlinks::class)->findEntry('alles-wird-gut')?->id())->toBe($this->origin->id());
});
