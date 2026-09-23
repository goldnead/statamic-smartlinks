<?php

use Goldnead\Smartlinks\LinkStatus;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

const ODESLI_APPLE = 'https://geo.music.apple.com/de/album/_/1491803543?i=1491803545&mt=1&app=itunes&ls=1&at=1000lHKX&ct=api_http&itscg=30200&itsct=odsl_m';

it('cleans links when a song is saved', function () {
    $song = $this->makeSong('Alles wird gut', [['platform' => 'applemusic', 'url' => ODESLI_APPLE]]);

    expect(Entry::find($song->id())->get('streaming_links'))->toBe([
        ['platform' => 'applemusic', 'url' => 'https://music.apple.com/de/album/_/1491803543?i=1491803545'],
    ]);
});

it('leaves links alone on save when switched off, and in other collections', function () {
    config(['smartlinks.cleanup.on_save' => false]);
    $song = $this->makeSong('Roh', [ODESLI_APPLE]);
    expect(Entry::find($song->id())->get('streaming_links')[0]['url'])->toBe(ODESLI_APPLE);

    config(['smartlinks.cleanup.on_save' => true]);
    Collection::make('pages')->save();
    $page = Entry::make()->collection('pages')->slug('p')->data(['streaming_links' => [['url' => ODESLI_APPLE]]]);
    $page->save();
    expect(Entry::find($page->id())->get('streaming_links')[0]['url'])->toBe(ODESLI_APPLE);
});

it('carries a link\'s check history to its cleaned URL and drops rows of links that are gone', function () {
    config(['smartlinks.cleanup.on_save' => false]);
    $song = $this->makeSong('Alt', [ODESLI_APPLE, 'https://www.deezer.com/track/1']);
    config(['smartlinks.cleanup.on_save' => true]);
    $status = app(LinkStatus::class);
    $status->record((string) $song->id(), ODESLI_APPLE, 'dead', 404);
    $status->record((string) $song->id(), ODESLI_APPLE, 'dead', 404);
    $status->record((string) $song->id(), 'https://removed.test/x', 'ok', 200);

    $this->artisan('smartlinks:clean')->assertSuccessful();

    expect($status->dead((string) $song->id()))->toBe(['https://music.apple.com/de/album/_/1491803543?i=1491803545'])
        ->and(DB::table('smartlinks_link_status')->count())->toBe(1);

    // Saving from the CP keeps them in step as well.
    $status->record((string) $song->id(), 'https://www.deezer.com/track/1', 'ok', 200);
    $fresh = Entry::find($song->id());
    $fresh->set('streaming_links', [['url' => 'https://www.deezer.com/de/track/1']])->save();

    expect(DB::table('smartlinks_link_status')->pluck('url')->all())->toBe(['https://www.deezer.com/track/1']);
});

it('cleans existing data with smartlinks:clean, dry run first', function () {
    config(['smartlinks.cleanup.on_save' => false]);
    $song = $this->makeSong('Alt', [ODESLI_APPLE, 'https://listen.tidal.com/track/1', 'https://www.deezer.com/track/1']);
    config(['smartlinks.cleanup.on_save' => true]);

    $this->artisan('smartlinks:clean', ['--dry-run' => true])
        ->expectsOutputToContain('2 URL(s) in 1 song(s) would be cleaned. Dry run: nothing saved.')
        ->assertSuccessful();
    expect(Entry::find($song->id())->get('streaming_links')[0]['url'])->toBe(ODESLI_APPLE);

    $this->artisan('smartlinks:clean')->expectsOutputToContain('2 URL(s) in 1 song(s) cleaned.')->assertSuccessful();
    expect(array_column(Entry::find($song->id())->get('streaming_links'), 'url'))->toBe([
        'https://music.apple.com/de/album/_/1491803543?i=1491803545',
        'https://tidal.com/track/1',
        'https://www.deezer.com/track/1',
    ]);
});
