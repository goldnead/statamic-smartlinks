<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

beforeEach(function () {
    config([
        'smartlinks.services.spotify.client_id' => 'id',
        'smartlinks.services.spotify.client_secret' => 'secret',
    ]);
    Http::fake([
        'accounts.spotify.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'api.spotify.com/*' => Http::response(['name' => 'Song', 'artists' => [['name' => 'ANDERS']], 'external_ids' => ['isrc' => 'DEA912300001']]),
        'api.deezer.com/*' => Http::response(['link' => 'https://www.deezer.com/track/1069843552']),
    ]);
});

it('fills missing links and never overwrites an existing one', function () {
    $existing = [
        ['platform' => 'tidal', 'link_type' => 'Song', 'url' => 'https://listen.tidal.com/track/1'],
        ['platform' => 'deezer', 'link_type' => 'Song', 'url' => 'https://www.deezer.com/track/HANDGEPFLEGT'],
    ];
    $song = $this->makeSong('Alles wird gut', $existing, ['spotify_id' => '1w0r0NDXEByTCr7wa5HjNK']);

    $this->artisan('smartlinks:resolve', ['entry' => $song->id()])->assertSuccessful();

    $links = Entry::find($song->id())->get('streaming_links');

    expect(array_slice($links, 0, 2))->toBe($existing)
        ->and($links)->toHaveCount(3)
        ->and($links[2])->toBe(['url' => 'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);

    // A second run finds nothing to add.
    $this->artisan('smartlinks:resolve', ['entry' => 'alles-wird-gut'])->assertSuccessful();
    expect(Entry::find($song->id())->get('streaming_links'))->toHaveCount(3);
});

it('saves nothing on a dry run, and says what it would add', function () {
    $song = $this->makeSong('Alles wird gut', [], ['spotify_id' => '1w0r0NDXEByTCr7wa5HjNK']);

    $this->artisan('smartlinks:resolve', ['--dry-run' => true])
        ->expectsOutputToContain('https://www.deezer.com/track/1069843552')
        ->expectsOutputToContain('2 link(s) would be added for 1 song(s). Dry run: nothing saved.')
        ->assertSuccessful();

    expect(Entry::find($song->id())->get('streaming_links'))->toBe([]);
});

it('logs each decision with its reason code', function () {
    Log::spy();
    $song = $this->makeSong('Ohne ID');

    $this->artisan('smartlinks:resolve', ['entry' => $song->id()])
        ->expectsOutputToContain('missing_input')
        ->assertSuccessful();

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'smartlinks: resolve'
        && $context['platform'] === 'deezer' && $context['reason'] === 'missing_input');
});

it('appends to a plain list of URLs as a URL', function () {
    $song = Entry::make()->collection('songs')->slug('liste')->data([
        'title' => 'Liste',
        'spotify_id' => '1w0r0NDXEByTCr7wa5HjNK',
        'streaming_links' => ['https://listen.tidal.com/track/1'],
    ]);
    $song->save();

    $this->artisan('smartlinks:resolve', ['entry' => 'liste'])->assertSuccessful();

    expect(Entry::find($song->id())->get('streaming_links'))->toBe([
        'https://listen.tidal.com/track/1',
        'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK',
        'https://www.deezer.com/track/1069843552',
    ]);
});

it('fails for an entry that is not a song', function () {
    $this->artisan('smartlinks:resolve', ['entry' => 'gibt-es-nicht'])->assertFailed();
});
