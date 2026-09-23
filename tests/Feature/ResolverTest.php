<?php

use Goldnead\Smartlinks\Resolvers\DeezerResolver;
use Goldnead\Smartlinks\Resolvers\Resolution;
use Goldnead\Smartlinks\Resolvers\ResolverChain;
use Goldnead\Smartlinks\Resolvers\SpotifyClient;
use Goldnead\Smartlinks\Resolvers\Track;
use Goldnead\Smartlinks\Resolvers\YouTubeResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const SPOTIFY_ID = '1w0r0NDXEByTCr7wa5HjNK';

function fakeSpotify(): void
{
    config([
        'smartlinks.services.spotify.client_id' => 'id',
        'smartlinks.services.spotify.client_secret' => 'secret',
    ]);
}

function spotifyResponses(): array
{
    return [
        'accounts.spotify.com/api/token' => Http::response(['access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600]),
        'api.spotify.com/v1/tracks/*' => Http::response([
            'name' => 'Alles wird gut',
            'artists' => [['name' => 'ANDERS']],
            'external_ids' => ['isrc' => 'DEA912300001'],
            'external_urls' => ['spotify' => 'https://open.spotify.com/track/'.SPOTIFY_ID],
        ]),
    ];
}

it('looks the track up on Spotify with client credentials and caches the token', function () {
    fakeSpotify();
    Http::fake(spotifyResponses());

    $track = new Track(spotifyId: SPOTIFY_ID);
    expect(app(SpotifyClient::class)->enrich($track))->toBe(Resolution::FOUND)
        ->and($track->isrc)->toBe('DEA912300001')
        ->and($track->title)->toBe('Alles wird gut')
        ->and($track->artist)->toBe('ANDERS');

    app(SpotifyClient::class)->enrich(new Track(spotifyId: SPOTIFY_ID));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://accounts.spotify.com/api/token'
        && $r['grant_type'] === 'client_credentials'
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('id:secret')));
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.spotify.com/v1/tracks/'.SPOTIFY_ID)
        && $r->hasHeader('Authorization', 'Bearer tok'));
    Http::assertSentCount(3); // one token, two tracks
});

it('reports Spotify without credentials as not configured, without a request', function () {
    Http::fake();

    expect(app(SpotifyClient::class)->enrich(new Track(spotifyId: SPOTIFY_ID)))->toBe(Resolution::NOT_CONFIGURED);
    Http::assertNothingSent();
});

it('reports a rejected Spotify token as an HTTP error', function () {
    fakeSpotify();
    Http::fake(['accounts.spotify.com/*' => Http::response(['error' => 'invalid_client'], 400)]);

    expect(app(SpotifyClient::class)->enrich(new Track(spotifyId: SPOTIFY_ID)))->toBe(Resolution::HTTP_ERROR);
});

it('finds the Deezer link by ISRC', function () {
    Http::fake(['api.deezer.com/track/isrc:DEA912300001' => Http::response([
        'id' => 1069843552,
        'link' => 'https://www.deezer.com/track/1069843552',
    ])]);

    $result = app(DeezerResolver::class)->resolve(new Track(isrc: 'DEA912300001'));

    expect($result->successful())->toBeTrue()
        ->and($result->url)->toBe('https://www.deezer.com/track/1069843552');
});

it('treats Deezer\'s 200-with-error as not found, and a foreign link as not found', function () {
    Http::fake([
        'api.deezer.com/track/isrc:DEA912300001' => Http::response(['error' => ['type' => 'DataException', 'message' => 'no data', 'code' => 800]]),
        'api.deezer.com/track/isrc:DEA912300002' => Http::response(['link' => 'https://evil.test/track/1']),
        'api.deezer.com/track/isrc:DEA912300003' => Http::response('', 503),
    ]);

    expect(app(DeezerResolver::class)->resolve(new Track(isrc: 'DEA912300001'))->reason)->toBe(Resolution::NOT_FOUND)
        ->and(app(DeezerResolver::class)->resolve(new Track(isrc: 'DEA912300002'))->reason)->toBe(Resolution::NOT_FOUND)
        ->and(app(DeezerResolver::class)->resolve(new Track(isrc: 'DEA912300003'))->reason)->toBe(Resolution::HTTP_ERROR)
        ->and(app(DeezerResolver::class)->resolve(new Track)->reason)->toBe(Resolution::MISSING_INPUT);
});

it('takes a YouTube video only from the artist\'s Topic channel', function () {
    config(['smartlinks.services.youtube.key' => 'yt-key']);
    Http::fake(['www.googleapis.com/youtube/v3/search*' => Http::response(['items' => [
        ['id' => ['videoId' => 'AAAAAAAAAAA'], 'snippet' => ['channelTitle' => 'Fan Uploads']],
        ['id' => ['videoId' => 'yG4VfxlXbIc'], 'snippet' => ['channelTitle' => 'ANDERS - Topic']],
    ]])]);

    $result = app(YouTubeResolver::class)->resolve(new Track(title: 'Alles wird gut', artist: 'ANDERS'));

    expect($result->url)->toBe('https://www.youtube.com/watch?v=yG4VfxlXbIc');
    Http::assertSent(fn (Request $r) => $r['key'] === 'yt-key' && $r['q'] === 'ANDERS Alles wird gut');
});

it('leaves YouTube empty rather than guess', function () {
    config(['smartlinks.services.youtube.key' => 'yt-key']);
    Http::fake(['www.googleapis.com/*' => Http::response(['items' => [
        ['id' => ['videoId' => 'AAAAAAAAAAA'], 'snippet' => ['channelTitle' => 'Fan Uploads']],
    ]])]);

    expect(app(YouTubeResolver::class)->resolve(new Track(title: 'X', artist: 'ANDERS'))->reason)->toBe(Resolution::NO_CONFIDENT_MATCH);

    config(['smartlinks.services.youtube.key' => null]);
    expect(app(YouTubeResolver::class)->resolve(new Track(title: 'X', artist: 'ANDERS'))->reason)->toBe(Resolution::NOT_CONFIGURED);
});

it('runs the chain: Spotify from the ID, Deezer via the ISRC Spotify returned', function () {
    fakeSpotify();
    Http::fake([
        ...spotifyResponses(),
        'api.deezer.com/*' => Http::response(['link' => 'https://www.deezer.com/track/1069843552']),
    ]);
    $song = $this->makeSong('Alles wird gut', [], ['spotify_id' => SPOTIFY_ID]);

    $run = app(ResolverChain::class)->run($song);

    expect($run['enrichment'])->toBe(Resolution::FOUND)
        ->and(collect($run['results'])->mapWithKeys(fn ($r) => [$r->platform => $r->url ?? $r->reason])->all())->toBe([
            'spotify' => 'https://open.spotify.com/track/'.SPOTIFY_ID,
            'deezer' => 'https://www.deezer.com/track/1069843552',
            'youtube' => Resolution::NOT_CONFIGURED,
        ]);
});

it('takes the Spotify ID from a stored Spotify link when the ID field is empty, and the ISRC from its own field', function () {
    config(['smartlinks.isrc_field' => 'isrc']);
    Http::fake(['api.deezer.com/track/isrc:DEA912300001' => Http::response(['link' => 'https://www.deezer.com/track/9'])]);
    $song = $this->makeSong('Song', ['https://open.spotify.com/intl-de/track/'.SPOTIFY_ID], ['isrc' => 'DEA912300001']);

    $track = app(ResolverChain::class)->trackFor($song);
    $run = app(ResolverChain::class)->run($song);

    expect($track->spotifyId)->toBe(SPOTIFY_ID)
        ->and($run['enrichment'])->toBe(Resolution::NOT_CONFIGURED)
        ->and($run['results'][0]->reason)->toBe(ResolverChain::ALREADY_PRESENT)
        ->and($run['results'][1]->url)->toBe('https://www.deezer.com/track/9');
});

it('refuses a configured resolver that does not implement the contract', function () {
    config(['smartlinks.resolvers' => [stdClass::class]]);

    app(ResolverChain::class)->resolvers();
})->throws(InvalidArgumentException::class);
