<?php

use Goldnead\Smartlinks\Resolvers\AppleMusicResolver;
use Goldnead\Smartlinks\Resolvers\DeezerResolver;
use Goldnead\Smartlinks\Resolvers\Identifier;
use Goldnead\Smartlinks\Resolvers\Resolution;
use Goldnead\Smartlinks\Resolvers\ResolverChain;
use Goldnead\Smartlinks\Resolvers\SpotifyResolver;
use Goldnead\Smartlinks\Resolvers\TidalResolver;
use Goldnead\Smartlinks\Resolvers\Track;
use Goldnead\Smartlinks\Suggestions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/*
 * ANDERS "Alles wird gut", the live probe of 23.09.2026: ISRC DEHY12002800,
 * UPC 4250548419796, Deezer track 1069843552 in album 171123492, Apple
 * album 1530381797 track 1530381799.
 */
const ISRC = 'DEHY12002800';
const UPC = '4250548419796';
const DEEZER_TRACK = 'https://www.deezer.com/track/1069843552';
const APPLE_TRACK = 'https://music.apple.com/de/album/alles-wird-gut/1530381797?i=1530381799';

function deezerTrack(array $override = []): array
{
    return [
        'id' => 1069843552,
        'isrc' => ISRC,
        'title' => 'Alles wird gut',
        'link' => DEEZER_TRACK,
        'duration' => 197,
        'track_position' => 1,
        'disk_number' => 1,
        'available_countries' => ['DE', 'AT', 'CH'],
        'artist' => ['name' => 'Anders'],
        'album' => ['id' => 171123492],
        ...$override,
    ];
}

function deezerAlbum(): array
{
    return ['id' => 171123492, 'upc' => UPC, 'title' => 'Alles wird gut', 'link' => 'https://www.deezer.com/album/171123492', 'artist' => ['name' => 'Anders']];
}

function itunesAlbum(array $song = []): array
{
    return ['resultCount' => 2, 'results' => [
        ['wrapperType' => 'collection', 'collectionType' => 'Album', 'collectionId' => 1530381797, 'collectionViewUrl' => 'https://music.apple.com/de/album/alles-wird-gut/1530381797?uo=4'],
        ['wrapperType' => 'track', 'kind' => 'song', 'trackId' => 1530381799, 'trackNumber' => 1, 'discNumber' => 1, 'trackTimeMillis' => 197000,
            'trackViewUrl' => 'https://music.apple.com/de/album/alles-wird-gut/1530381797?i=1530381799&uo=4', ...$song],
    ]];
}

function fakeCatalogue(array $extra = []): void
{
    Http::fake([
        ...$extra,
        'api.deezer.com/track/1069843552' => Http::response(deezerTrack()),
        'api.deezer.com/track/isrc:'.ISRC => Http::response(deezerTrack()),
        'api.deezer.com/album/171123492' => Http::response(deezerAlbum()),
        'api.deezer.com/album/upc:'.UPC => Http::response(deezerAlbum()),
        'itunes.apple.com/lookup*' => Http::response(itunesAlbum()),
    ]);
}

function withIdentifierFields(): void
{
    config(['smartlinks.isrc_field' => 'isrc', 'smartlinks.upc_field' => 'upc']);
    Blueprint::make('song')->setNamespace('collections.songs')->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
        ['handle' => 'title', 'field' => ['type' => 'text']],
        ['handle' => 'streaming_links', 'field' => ['type' => 'grid', 'fields' => [['handle' => 'url', 'field' => ['type' => 'smartlink_url']]]]],
        ['handle' => 'isrc', 'field' => ['type' => 'text']],
        ['handle' => 'upc', 'field' => ['type' => 'text']],
    ]]]]]])->save();
}

it('derives ISRC and UPC from one Deezer link and fills Apple Music from them', function () {
    fakeCatalogue();
    $song = $this->makeSong('Alles wird gut', [DEEZER_TRACK]);

    $run = app(ResolverChain::class)->fill($song);

    expect($run['enrichment'])->toBe(Resolution::FOUND)
        ->and($run['track']->isrc)->toBe(ISRC)
        ->and($run['track']->upc)->toBe(UPC)
        ->and(array_map(fn ($r) => [$r->platform, $r->url], $run['added']))->toBe([['applemusic', APPLE_TRACK]])
        ->and(array_column(Entry::find($song->id())->get('streaming_links'), 'url'))->toBe([DEEZER_TRACK, APPLE_TRACK]);

    // The UPC lookup in the DE storefront, songs included.
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://itunes.apple.com/lookup')
        && $r['upc'] === UPC && $r['entity'] === 'song' && $r['country'] === 'de');
});

it('stores ISRC and UPC on the entry when the blueprint has fields for them, and starts there next time', function () {
    withIdentifierFields();
    fakeCatalogue();
    $song = $this->makeSong('Alles wird gut', [DEEZER_TRACK]);

    app(ResolverChain::class)->fill($song);
    $fresh = Entry::find($song->id());

    expect($fresh->get('isrc'))->toBe(ISRC)->and($fresh->get('upc'))->toBe(UPC);

    $track = app(ResolverChain::class)->trackFor($fresh);
    expect($track->isrc)->toBe(ISRC)->and($track->upc)->toBe(UPC);
});

it('does not store identifiers the blueprint has no field for, nor on a dry run', function () {
    config(['smartlinks.isrc_field' => 'isrc', 'smartlinks.upc_field' => 'upc']);
    fakeCatalogue();
    $song = $this->makeSong('Alles wird gut', [DEEZER_TRACK]);

    app(ResolverChain::class)->fill($song);
    expect(Entry::find($song->id())->get('isrc'))->toBeNull();

    withIdentifierFields();
    $other = $this->makeSong('Zwei', [DEEZER_TRACK]);
    app(ResolverChain::class)->fill($other, dryRun: true);
    expect(Entry::find($other->id())->get('isrc'))->toBeNull();
});

it('does not link Deezer where the track is not available in the configured country', function () {
    Http::fake(['api.deezer.com/track/isrc:*' => Http::response(deezerTrack(['available_countries' => ['FR', 'BE']]))]);

    expect(app(DeezerResolver::class)->resolve(new Track(isrc: ISRC))->reason)->toBe(Resolution::NOT_AVAILABLE_IN_REGION);

    config(['smartlinks.country' => 'FR']);
    expect(app(DeezerResolver::class)->resolve(new Track(isrc: ISRC))->url)->toBe(DEEZER_TRACK);
});

it('retries Deezer once after its quota or busy answer', function () {
    Sleep::fake();
    Http::fake(['api.deezer.com/track/isrc:*' => Http::sequence()
        ->push(['error' => ['type' => 'Exception', 'message' => 'Quota limit exceeded', 'code' => 4]])
        ->push(deezerTrack())]);

    expect(app(DeezerResolver::class)->resolve(new Track(isrc: ISRC))->url)->toBe(DEEZER_TRACK);
    Sleep::assertSleptTimes(1);
});

it('reports Deezer quota and mismatching answers', function () {
    Sleep::fake();
    Http::fake([
        'api.deezer.com/track/isrc:DEHY12002800' => Http::response(['error' => ['type' => 'Exception', 'message' => 'Quota limit exceeded', 'code' => 4]]),
        'api.deezer.com/track/isrc:DEHY12002801' => Http::response(deezerTrack(['isrc' => 'DEHY99999999'])),
        'api.deezer.com/album/upc:*' => Http::response(deezerAlbum()),
    ]);

    expect(app(DeezerResolver::class)->resolve(new Track(isrc: ISRC))->reason)->toBe(Resolution::RATE_LIMITED)
        ->and(app(DeezerResolver::class)->resolve(new Track(isrc: 'DEHY12002801'))->reason)->toBe(Resolution::MISMATCH)
        ->and(app(DeezerResolver::class)->resolve(new Track(upc: UPC, album: true))->url)->toBe('https://www.deezer.com/album/171123492');
});

it('picks the Apple track by position and checks its length', function () {
    $track = fn () => tap(new Track(isrc: ISRC, upc: UPC), function (Track $t) {
        $t->trackNumber = 1;
        $t->discNumber = 1;
        $t->duration = 197;
    });

    Http::fake(['itunes.apple.com/*' => Http::sequence()
        ->push(itunesAlbum())
        ->push(itunesAlbum(['trackTimeMillis' => 240000]))
        ->push(itunesAlbum(['trackNumber' => 2]))
        ->push(['resultCount' => 0, 'results' => []])
        ->push('<html>busy</html>', 200)
        ->push('', 403)]);

    $apple = app(AppleMusicResolver::class);

    expect($apple->resolve($track())->url)->toBe(APPLE_TRACK)
        ->and($apple->resolve($track())->reason)->toBe(Resolution::MISMATCH)
        ->and($apple->resolve($track())->reason)->toBe(Resolution::NO_CONFIDENT_MATCH)
        ->and($apple->resolve($track())->reason)->toBe(Resolution::NOT_FOUND)
        ->and($apple->resolve($track())->reason)->toBe(Resolution::HTTP_ERROR)
        ->and($apple->resolve($track())->reason)->toBe(Resolution::RATE_LIMITED)
        ->and($apple->resolve(new Track(isrc: ISRC))->reason)->toBe(Resolution::MISSING_INPUT);
});

it('links the Apple album, not a track, for a release', function () {
    Http::fake(['itunes.apple.com/*' => Http::response(itunesAlbum())]);

    expect(app(AppleMusicResolver::class)->resolve(new Track(upc: UPC, album: true))->url)
        ->toBe('https://music.apple.com/de/album/alles-wird-gut/1530381797');
});

it('spaces iTunes calls to stay under Apple\'s 20 a minute', function () {
    config(['smartlinks.services.itunes.interval_ms' => 3100]);
    Sleep::fake();
    Http::fake(['itunes.apple.com/*' => Http::response(itunesAlbum())]);

    app(AppleMusicResolver::class)->resolve(new Track(upc: UPC, album: true));
    app(AppleMusicResolver::class)->resolve(new Track(upc: UPC, album: true));

    Sleep::assertSleptTimes(1);
});

it('finds Spotify by exact ISRC or UPC search with client credentials', function () {
    config(['smartlinks.services.spotify.client_id' => 'id', 'smartlinks.services.spotify.client_secret' => 'secret']);
    Http::fake([
        'accounts.spotify.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'api.spotify.com/v1/search*' => function (Request $r) {
            return match ($r['q']) {
                'isrc:'.ISRC => Http::response(['tracks' => ['items' => [['external_ids' => ['isrc' => ISRC], 'external_urls' => ['spotify' => 'https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']]]]]),
                'isrc:DEHY12002801' => Http::response(['tracks' => ['items' => [['external_ids' => ['isrc' => 'XX0000000000'], 'external_urls' => ['spotify' => 'https://open.spotify.com/track/x']]]]]),
                'isrc:DEHY12002802' => Http::response(['tracks' => ['items' => []]]),
                'isrc:DEHY12002803' => Http::response(['error' => ['status' => 429]], 429, ['Retry-After' => '30']),
                'upc:'.UPC => Http::response(['albums' => ['items' => [['external_urls' => ['spotify' => 'https://open.spotify.com/album/0abcdefghijklmnopqrstu']]]]]),
            };
        },
    ]);
    $spotify = app(SpotifyResolver::class);

    expect($spotify->resolve(new Track(isrc: ISRC))->url)->toBe('https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK')
        ->and($spotify->resolve(new Track(isrc: 'DEHY12002801'))->reason)->toBe(Resolution::MISMATCH)
        ->and($spotify->resolve(new Track(isrc: 'DEHY12002802'))->reason)->toBe(Resolution::NOT_FOUND)
        ->and($spotify->resolve(new Track(isrc: 'DEHY12002803'))->reason)->toBe(Resolution::RATE_LIMITED)
        ->and($spotify->resolve(new Track(upc: UPC, album: true))->url)->toBe('https://open.spotify.com/album/0abcdefghijklmnopqrstu');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/search') && $r['type'] === 'album' && $r['market'] === 'DE');
    // One token for all five searches.
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'accounts.spotify.com'));
    expect(collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'accounts.spotify.com'))->count())->toBe(1);
});

it('finds Tidal by ISRC filter in the DE catalogue, preferring the sharing link', function () {
    config(['smartlinks.services.tidal.client_id' => 'tid', 'smartlinks.services.tidal.client_secret' => 'tsecret']);
    Http::fake([
        'auth.tidal.com/*' => Http::response(['access_token' => 'ttok', 'expires_in' => 86400]),
        'openapi.tidal.com/v2/tracks*' => function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return match ($query['filter']['isrc'] ?? null) {
                ISRC => Http::response(['data' => [
                    ['id' => '999', 'type' => 'tracks', 'attributes' => ['isrc' => 'OTHER0000000']],
                    ['id' => '172804280', 'type' => 'tracks', 'attributes' => ['isrc' => ISRC, 'externalLinks' => [
                        ['href' => 'https://tidal.com/track/172804280/u', 'meta' => ['type' => 'TIDAL_SHARING']],
                    ]]],
                ]]),
                'DEHY12002801' => Http::response(['data' => [['id' => '1', 'attributes' => ['isrc' => 'OTHER0000000']]]]),
                'DEHY12002802' => Http::response(['data' => []]),
                'DEHY12002803' => Http::response([], 429),
            };
        },
        'openapi.tidal.com/v2/albums*' => Http::response(['data' => [['id' => '155209493', 'attributes' => ['barcodeId' => UPC]]]]),
    ]);
    $tidal = app(TidalResolver::class);

    expect($tidal->resolve(new Track(isrc: ISRC))->url)->toBe('https://tidal.com/track/172804280')
        ->and($tidal->resolve(new Track(isrc: 'DEHY12002801'))->reason)->toBe(Resolution::MISMATCH)
        ->and($tidal->resolve(new Track(isrc: 'DEHY12002802'))->reason)->toBe(Resolution::NOT_FOUND)
        ->and($tidal->resolve(new Track(isrc: 'DEHY12002803'))->reason)->toBe(Resolution::RATE_LIMITED)
        ->and($tidal->resolve(new Track(upc: UPC, album: true))->url)->toBe('https://tidal.com/album/155209493');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openapi.tidal.com') && $r['countryCode'] === 'DE'
        && $r->hasHeader('Authorization', 'Bearer ttok'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://auth.tidal.com/v1/oauth2/token'
        && $r['grant_type'] === 'client_credentials' && $r->hasHeader('Authorization', 'Basic '.base64_encode('tid:tsecret')));

    config(['smartlinks.services.tidal.client_id' => null]);
    expect($tidal->resolve(new Track(isrc: ISRC))->reason)->toBe(Resolution::NOT_CONFIGURED);
});

it('identifies from a Spotify or Tidal link when credentials exist', function () {
    config([
        'smartlinks.services.spotify.client_id' => 'id', 'smartlinks.services.spotify.client_secret' => 'secret',
        'smartlinks.services.tidal.client_id' => 'tid', 'smartlinks.services.tidal.client_secret' => 'tsecret',
    ]);
    fakeCatalogue([
        'accounts.spotify.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        'api.spotify.com/v1/tracks/*' => Http::response(['name' => 'Alles wird gut', 'artists' => [['name' => 'Anders']], 'external_ids' => ['isrc' => ISRC]]),
        'auth.tidal.com/*' => Http::response(['access_token' => 'ttok', 'expires_in' => 86400]),
        'openapi.tidal.com/v2/tracks/172804280*' => Http::response(['data' => ['id' => '172804280', 'attributes' => ['isrc' => ISRC]]]),
    ]);
    $identifier = app(Identifier::class);

    $fromSpotify = $identifier->fromUrls(['https://open.spotify.com/track/1w0r0NDXEByTCr7wa5HjNK']);
    $fromTidal = $identifier->fromUrls(['https://tidal.com/track/172804280']);

    expect($identifier->enrich($fromSpotify))->toBe(Resolution::FOUND)
        ->and($fromSpotify->upc)->toBe(UPC)
        ->and($identifier->enrich($fromTidal))->toBe(Resolution::FOUND)
        ->and($fromTidal->isrc)->toBe(ISRC)
        ->and($fromTidal->deezerLink)->toBe(DEEZER_TRACK);
});

it('stays unidentified without a key or a link that yields one, and never searches by name', function () {
    Http::fake();
    $song = $this->makeSong('Nur ein Name', ['https://www.boomplay.com/songs/1', 'https://music.yandex.ru/track/1']);

    $run = app(ResolverChain::class)->run($song);

    expect($run['enrichment'])->toBe(Resolution::MISSING_INPUT)
        ->and(collect($run['results'])->pluck('reason')->unique()->values()->all())->toBe([Resolution::MISSING_INPUT, Resolution::NOT_CONFIGURED]);
    Http::assertNothingSent();
});

it('keeps going when one service fails', function () {
    config(['smartlinks.services.tidal.client_id' => 'tid', 'smartlinks.services.tidal.client_secret' => 'tsecret']);
    fakeCatalogue([
        'auth.tidal.com/*' => fn () => throw new ConnectionException('down'),
    ]);
    $song = $this->makeSong('Alles wird gut', [DEEZER_TRACK]);

    $run = app(ResolverChain::class)->run($song);
    $byPlatform = collect($run['results'])->keyBy('platform');

    expect($byPlatform['tidal']->reason)->toBe(Resolution::HTTP_ERROR)
        ->and($byPlatform['applemusic']->url)->toBe(APPLE_TRACK);
});

it('resolves a release by UPC from a Deezer album link', function () {
    config(['smartlinks.release_collections' => ['releases']]);
    Collection::make('releases')->save();
    fakeCatalogue();
    $release = Entry::make()->collection('releases')->slug('alles-wird-gut-single')
        ->data(['title' => 'Alles wird gut', 'streaming_links' => [['url' => 'https://www.deezer.com/album/171123492']]]);
    $release->save();

    $run = app(ResolverChain::class)->fill($release);

    expect($run['track']->album)->toBeTrue()
        ->and($run['track']->upc)->toBe(UPC)
        ->and(array_map(fn ($r) => $r->url, $run['added']))->toBe(['https://music.apple.com/de/album/alles-wird-gut/1530381797']);

    // A release has a landing page like a song.
    $this->get('/hoeren/alles-wird-gut-single')->assertOk()->assertSee('Apple Music');
});

it('stores a YouTube name match as a pending suggestion, never as a link, and does not ask again', function () {
    config(['smartlinks.services.youtube.key' => 'yt']);
    fakeCatalogue([
        'www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [
            ['id' => ['videoId' => 'yG4VfxlXbIc'], 'snippet' => ['channelTitle' => 'Anders - Topic']],
        ]]),
    ]);
    $song = $this->makeSong('Alles wird gut', [DEEZER_TRACK]);

    $run = app(ResolverChain::class)->fill($song);

    expect(array_map(fn ($r) => $r->url, $run['suggested']))->toBe(['https://www.youtube.com/watch?v=yG4VfxlXbIc'])
        ->and(collect(Entry::find($song->id())->get('streaming_links'))->pluck('url')->all())->not->toContain('https://www.youtube.com/watch?v=yG4VfxlXbIc')
        ->and(app(Suggestions::class)->pending((string) $song->id()))->toHaveCount(1);

    $again = app(ResolverChain::class)->run(Entry::find($song->id()));
    expect(collect($again['results'])->firstWhere('platform', 'youtube')->reason)->toBe(ResolverChain::ALREADY_SUGGESTED);

    // Rejected: the same URL is not suggested again.
    $id = app(Suggestions::class)->pending((string) $song->id())[0]->id;
    app(Suggestions::class)->mark($id, Suggestions::REJECTED);
    app(ResolverChain::class)->fill(Entry::find($song->id()));
    expect(DB::table(Suggestions::TABLE)->count())->toBe(1)
        ->and(app(Suggestions::class)->pending((string) $song->id()))->toBe([]);
});
