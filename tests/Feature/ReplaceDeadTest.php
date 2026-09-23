<?php

use Goldnead\Smartlinks\LinkStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\Entry;

const DEAD_APPLE = 'https://music.apple.com/de/album/_/1370926614?i=1370928001';
const LIVE_APPLE = 'https://music.apple.com/de/album/der-letzte-zug/1840984286?i=1840984294';

beforeEach(function () {
    Http::fake([
        'api.deezer.com/track/1' => Http::response(['id' => 1, 'isrc' => 'DEHY11800001', 'link' => 'https://www.deezer.com/track/1',
            'track_position' => 3, 'disk_number' => 1, 'duration' => 200, 'album' => ['id' => 5]]),
        'api.deezer.com/album/5' => Http::response(['id' => 5, 'upc' => '4250548400005']),
        'itunes.apple.com/*' => Http::response(['resultCount' => 1, 'results' => [
            ['wrapperType' => 'track', 'kind' => 'song', 'trackNumber' => 3, 'discNumber' => 1, 'trackTimeMillis' => 200000, 'trackViewUrl' => LIVE_APPLE.'&uo=4'],
        ]]),
    ]);

    $this->song = $this->makeSong('Der letzte Zug', [
        ['platform' => 'applemusic', 'link_type' => 'Song', 'url' => DEAD_APPLE],
        ['platform' => 'deezer', 'link_type' => 'Song', 'url' => 'https://www.deezer.com/track/1'],
    ]);
    $this->statuses = app(LinkStatus::class);
});

function confirmDead(string $entryId, string $url): void
{
    app(LinkStatus::class)->record($entryId, $url, LinkStatus::DEAD, 404);
    app(LinkStatus::class)->record($entryId, $url, LinkStatus::DEAD, 404);
}

it('leaves a platform with only dead links alone by default', function () {
    confirmDead((string) $this->song->id(), DEAD_APPLE);

    $this->artisan('smartlinks:resolve', ['entry' => $this->song->id()])->assertSuccessful();

    expect(Entry::find($this->song->id())->get('streaming_links'))->toHaveCount(2)
        ->and(Entry::find($this->song->id())->get('streaming_links')[0]['url'])->toBe(DEAD_APPLE);
});

it('replaces a confirmed dead link in its row with --replace-dead, keeping the other columns', function () {
    Log::spy();
    confirmDead((string) $this->song->id(), DEAD_APPLE);

    $this->artisan('smartlinks:resolve', ['entry' => $this->song->id(), '--replace-dead' => true])
        ->expectsOutputToContain('replaced')
        ->assertSuccessful();

    expect(Entry::find($this->song->id())->get('streaming_links'))->toBe([
        ['platform' => 'applemusic', 'link_type' => 'Song', 'url' => LIVE_APPLE],
        ['platform' => 'deezer', 'link_type' => 'Song', 'url' => 'https://www.deezer.com/track/1'],
    ])
        ->and(DB::table(LinkStatus::TABLE)->where('url', DEAD_APPLE)->exists())->toBeFalse()
        ->and($this->statuses->dead((string) $this->song->id()))->toBe([]);

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'smartlinks: replaced dead link'
        && $context['old'] === DEAD_APPLE && $context['new'] === LIVE_APPLE);
});

it('does not replace on a dry run', function () {
    confirmDead((string) $this->song->id(), DEAD_APPLE);

    $this->artisan('smartlinks:resolve', ['entry' => $this->song->id(), '--replace-dead' => true, '--dry-run' => true])
        ->expectsOutputToContain('would replace')
        ->assertSuccessful();

    expect(Entry::find($this->song->id())->get('streaming_links')[0]['url'])->toBe(DEAD_APPLE);
});

it('never touches a suspect or unknown link, nor a platform that still has a live link', function (callable $setup) {
    $setup($this);

    $this->artisan('smartlinks:resolve', ['entry' => $this->song->id(), '--replace-dead' => true])->assertSuccessful();

    expect(Entry::find($this->song->id())->get('streaming_links')[0]['url'])->toBe(DEAD_APPLE);
})->with([
    'suspect (one strike)' => [fn ($t) => $t->statuses->record((string) $t->song->id(), DEAD_APPLE, LinkStatus::DEAD, 404)],
    'unknown' => [fn ($t) => $t->statuses->record((string) $t->song->id(), DEAD_APPLE, LinkStatus::UNKNOWN, 503)],
    'a live link of the platform exists' => [function ($t) {
        confirmDead((string) $t->song->id(), DEAD_APPLE);
        $entry = Entry::find($t->song->id());
        $entry->set('streaming_links', [...$entry->get('streaming_links'), ['url' => 'https://music.apple.com/de/album/x/1?i=2']])->save();
    }],
]);
