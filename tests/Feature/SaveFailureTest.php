<?php

use Goldnead\Smartlinks\LinkStatus;
use Goldnead\Smartlinks\Suggestions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

const SAVE_DEAD_APPLE = 'https://music.apple.com/de/album/_/1370926614?i=1370928001';
const SAVE_LIVE_APPLE = 'https://music.apple.com/de/album/der-letzte-zug/1840984286?i=1840984294';

/** Makes every save of an entry fail, the way a cancelled EntrySaving does. */
function cancelSaves(): void
{
    Event::listen(EntrySaving::class, fn () => false);
}

function saveFailureUser(): Statamic\Contracts\Auth\User
{
    $role = Role::make('save-failure-manager')->permissions(['access cp', 'view smartlinks', 'manage smartlinks']);
    $role->save();

    $user = User::make()->email(uniqid().'@example.com')->assignRole($role);
    $user->save();

    return $user;
}

beforeEach(function () {
    Http::fake([
        'api.deezer.com/track/1' => Http::response(['id' => 1, 'isrc' => 'DEHY11800001', 'link' => 'https://www.deezer.com/track/1',
            'track_position' => 3, 'disk_number' => 1, 'duration' => 200, 'album' => ['id' => 5]]),
        'api.deezer.com/album/5' => Http::response(['id' => 5, 'upc' => '4250548400005']),
        'itunes.apple.com/*' => Http::response(['resultCount' => 1, 'results' => [
            ['wrapperType' => 'track', 'kind' => 'song', 'trackNumber' => 3, 'discNumber' => 1, 'trackTimeMillis' => 200000, 'trackViewUrl' => SAVE_LIVE_APPLE.'&uo=4'],
        ]]),
    ]);
});

it('does not report a replacement whose save failed, and exits with failure', function () {
    // The Deezer row gives the ISRC the Apple lookup needs, as in ReplaceDeadTest.
    $song = $this->makeSong('Der letzte Zug', [
        ['platform' => 'applemusic', 'link_type' => 'Song', 'url' => SAVE_DEAD_APPLE],
        ['platform' => 'deezer', 'link_type' => 'Song', 'url' => 'https://www.deezer.com/track/1'],
    ]);
    app(LinkStatus::class)->record((string) $song->id(), SAVE_DEAD_APPLE, LinkStatus::DEAD, 404);
    app(LinkStatus::class)->record((string) $song->id(), SAVE_DEAD_APPLE, LinkStatus::DEAD, 404);

    cancelSaves();

    $this->artisan('smartlinks:resolve', ['entry' => $song->id(), '--replace-dead' => true])
        ->expectsOutputToContain('save_failed')
        ->doesntExpectOutputToContain('replaced')
        ->assertFailed();

    // The entry in memory goes back to the stored link, so a later save of the
    // same entry cannot write the replacement that was never saved.
    expect(Entry::find($song->id())->get('streaming_links')[0]['url'])->toBe(SAVE_DEAD_APPLE);
});

it('does not accept a suggestion whose save failed', function () {
    $song = $this->makeSong('Vorschlag', [['platform' => 'deezer', 'link_type' => 'Song', 'url' => 'https://www.deezer.com/track/3']]);
    app(Suggestions::class)->add((string) $song->id(), 'youtube', 'https://www.youtube.com/watch?v=yG4VfxlXbIc');
    $id = app(Suggestions::class)->pending((string) $song->id())[0]->id;

    cancelSaves();

    $this->actingAs(saveFailureUser())->post("/cp/smartlinks/suggestions/{$id}/accept")
        ->assertRedirect('/cp/smartlinks')
        ->assertSessionHas('error');

    expect(app(Suggestions::class)->find($id)->status)->toBe(Suggestions::PENDING);
});

it('keeps the status of a URL that appears twice exactly', function () {
    $url = 'https://www.deezer.com/track/1';
    $song = $this->makeSong('Dublette', [$url]);
    app(LinkStatus::class)->record((string) $song->id(), $url, LinkStatus::DEAD, 404);
    app(LinkStatus::class)->record((string) $song->id(), $url, LinkStatus::DEAD, 404);

    // Saving the entry runs the cleanup listener, which drops the exact duplicate row.
    $song->set('streaming_links', [['url' => $url], ['url' => $url]])->save();

    expect(Entry::find($song->id())->get('streaming_links'))->toHaveCount(1)
        ->and(app(LinkStatus::class)->dead((string) $song->id()))->toBe([$url]);
});

it('does not report an added link whose save failed, and exits with failure', function () {
    $song = $this->makeSong('Neu', [['platform' => 'deezer', 'link_type' => 'Song', 'url' => 'https://www.deezer.com/track/1']]);

    cancelSaves();

    $this->artisan('smartlinks:resolve', ['entry' => $song->id()])
        ->expectsOutputToContain('save_failed')
        ->assertFailed();

    $urls = collect(Entry::find($song->id())->get('streaming_links'))->pluck('url')->all();
    expect($urls)->toBe(['https://www.deezer.com/track/1']);
});
