<?php

/*
 * Live hit-rate harness. NOT part of the test suite and never run in CI: it
 * talks to the real services.
 *
 *     php tests/live/anders-hitrate.php [path-to-songs] [--limit=N]
 *
 * Reads the song files of anders-band.de (read only, nothing is written
 * anywhere), starts the resolver chain from each song's Deezer link alone,
 * and compares every service's answer with the link the site has stored:
 *
 *   identical         same track (same service ID) as stored
 *   different-valid   another URL than stored, and it answers 200
 *   new               nothing stored, found one that answers 200
 *   missing           stored, but the chain found nothing
 *   wrong             found a URL that does not answer 200
 *   none              nothing stored, nothing found
 *   n/a               service not configured (no credentials)
 *
 * Spotify and Tidal only run with SPOTIFY_CLIENT_ID/SECRET and
 * TIDAL_CLIENT_ID/SECRET in the environment.
 */

use Goldnead\Smartlinks\LinkChecker;
use Goldnead\Smartlinks\LinkCleaner;
use Goldnead\Smartlinks\LinkStatus;
use Goldnead\Smartlinks\Platforms;
use Goldnead\Smartlinks\Resolvers\AppleMusicResolver;
use Goldnead\Smartlinks\Resolvers\DeezerClient;
use Goldnead\Smartlinks\Resolvers\DeezerResolver;
use Goldnead\Smartlinks\Resolvers\Identifier;
use Goldnead\Smartlinks\Resolvers\Resolution;
use Goldnead\Smartlinks\Resolvers\SpotifyClient;
use Goldnead\Smartlinks\Resolvers\SpotifyResolver;
use Goldnead\Smartlinks\Resolvers\TidalClient;
use Goldnead\Smartlinks\Resolvers\TidalResolver;
use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Yaml\Yaml;

require __DIR__.'/../../vendor/autoload.php';

$args = array_slice($argv, 1);
$limit = null;
$dir = '/home/goldneros/projects/anders-band.de/content/collections/songs';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    } else {
        $dir = rtrim($arg, '/');
    }
}

// A bare container: the resolvers need config, cache and the HTTP client,
// not Statamic.
$app = new Container;
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('config', new Repository([
    'smartlinks' => require __DIR__.'/../../config/smartlinks.php',
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
]));
$app->singleton('cache', fn ($app) => new CacheManager($app));
$app->singleton(Factory::class, fn () => new Factory);
$app->singleton(Platforms::class, fn () => new Platforms);
$app->singleton(LinkCleaner::class);
$app->singleton(LinkChecker::class);
$app->singleton(Identifier::class, fn ($app) => new Identifier(
    new Smartlinks($app->make(Platforms::class)),
    $app->make(DeezerClient::class),
    $app->make(SpotifyClient::class),
    $app->make(TidalClient::class),
));

$platforms = $app->make(Platforms::class);
$cleaner = $app->make(LinkCleaner::class);
$checker = $app->make(LinkChecker::class);
$identifier = $app->make(Identifier::class);
$resolvers = [
    'deezer' => $app->make(DeezerResolver::class),
    'applemusic' => $app->make(AppleMusicResolver::class),
    'spotify' => $app->make(SpotifyResolver::class),
    'tidal' => $app->make(TidalResolver::class),
];

/** The service's own ID of a track URL, to compare forms that differ. */
function trackKey(string $platform, string $url): string
{
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return match ($platform) {
        'applemusic' => isset($query['i']) ? 'i:'.$query['i'] : (string) parse_url($url, PHP_URL_PATH),
        default => preg_match('~/track/([A-Za-z0-9]+)~', $url, $m) === 1 ? $m[1] : $url,
    };
}

$files = glob($dir.'/*.md') ?: [];
sort($files);
if ($limit !== null) {
    $files = array_slice($files, 0, $limit);
}

$tally = [];
$details = [];
$songs = 0;
$withDeezer = 0;
$identified = 0;

foreach ($files as $file) {
    $raw = (string) file_get_contents($file);
    if (preg_match('/^---\R(.*?)\R---/s', $raw, $m) !== 1) {
        continue;
    }
    $data = Yaml::parse($m[1]) ?? [];
    $songs++;
    $title = (string) ($data['title'] ?? basename($file));

    $stored = [];
    foreach ((array) ($data['streaming_links'] ?? []) as $row) {
        $url = is_array($row) ? ($row['url'] ?? null) : $row;
        if (is_string($url) && Platforms::isWebUrl($url)) {
            $clean = $cleaner->clean($url);
            $stored[$platforms->detect($clean)] ??= $clean;
        }
    }

    if (! isset($stored['deezer'])) {
        continue;
    }
    $withDeezer++;

    $track = $identifier->fromUrls([$stored['deezer']]);
    $reason = $identifier->enrich($track);
    if ($reason === Resolution::FOUND) {
        $identified++;
    }

    foreach ($resolvers as $platform => $resolver) {
        $result = $resolver->resolve($track);
        $have = $stored[$platform] ?? null;

        if ($result->reason === Resolution::NOT_CONFIGURED) {
            $class = 'n/a';
            $note = '';
        } elseif (! $result->successful()) {
            $class = $have !== null ? 'missing' : 'none';
            $note = $result->reason.($reason !== Resolution::FOUND ? " (identify: {$reason})" : '');
        } elseif ($have !== null && trackKey($platform, $have) === trackKey($platform, (string) $result->url)) {
            $class = 'identical';
            $note = '';
        } else {
            $ours = $checker->check((string) $result->url);
            $ok = $ours['status'] === LinkStatus::OK;
            $class = $have === null ? ($ok ? 'new' : 'wrong') : ($ok ? 'different-valid' : 'wrong');
            $note = 'http '.($ours['http_status'] ?? '-');

            if ($have !== null) {
                $theirs = $checker->check($have);
                $note .= ', stored: '.$theirs['status'].' (http '.($theirs['http_status'] ?? '-').')';
            }
        }

        $tally[$platform][$class] = ($tally[$platform][$class] ?? 0) + 1;
        if (! in_array($class, ['identical', 'none', 'n/a'], true)) {
            $details[] = sprintf('%-28s %-11s %-16s %s | ours %s | stored %s', mb_strimwidth($title, 0, 28), $platform, $class, $note, $result->url ?? '-', $have ?? '-');
        }
    }

    fwrite(STDERR, '.');
}

fwrite(STDERR, "\n");

$classes = ['identical', 'different-valid', 'new', 'missing', 'wrong', 'none', 'n/a'];
echo 'Run '.date('Y-m-d H:i').", {$songs} songs, {$withDeezer} with a Deezer link, {$identified} identified (ISRC).\n\n";
printf("%-11s %s\n", 'service', implode(' ', array_map(fn ($c) => str_pad($c, 15), $classes)));
foreach ($resolvers as $platform => $resolver) {
    printf("%-11s %s\n", $platform, implode(' ', array_map(fn ($c) => str_pad((string) ($tally[$platform][$c] ?? 0), 15), $classes)));
}
echo "\n".implode("\n", $details)."\n";
