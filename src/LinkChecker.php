<?php

namespace Goldnead\Smartlinks;

use Goldnead\Smartlinks\Contracts\HostResolver;
use Goldnead\Smartlinks\Support\IpGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Asks a stored link whether it still leads somewhere: HEAD first, GET when
 * HEAD is refused or inconclusive (many shops answer HEAD with 403 or 405).
 *
 * The verdict per check: `dead` only for 404 or 410. A 403 wall, a 429, a
 * 5xx, a timeout, a connect error or a host that does not resolve is
 * `unknown`: the network's word, not the link's. {@see LinkStatus} confirms
 * a dead link only on the second dead check in a row.
 *
 * SSRF guard: every hop's host is resolved first, and a single private,
 * loopback, link-local, reserved or metadata address refuses the URL
 * (`unknown`). Redirects are followed by hand, at most five, each hop
 * checked the same way, and the connection is pinned to the checked
 * address so DNS cannot change between the check and the request.
 */
class LinkChecker
{
    public const MAX_REDIRECTS = 5;

    /** @var array<string, float> host => time of the last request */
    protected array $lastByHost = [];

    public function __construct(protected HostResolver $resolver) {}

    /**
     * @return array{status: string, http_status: int|null}
     */
    public function check(string $url): array
    {
        if (! Platforms::isWebUrl($url)) {
            return ['status' => LinkStatus::DEAD, 'http_status' => null];
        }

        $head = $this->follow('head', $url);

        if ($head instanceof Response && ($head->successful())) {
            return ['status' => LinkStatus::OK, 'http_status' => $head->status()];
        }

        if ($head === false) {
            // Refused by the guard, unresolvable, or too many redirects:
            // GET would meet the same wall.
            return ['status' => LinkStatus::UNKNOWN, 'http_status' => null];
        }

        $get = $this->follow('get', $url);

        if (! $get instanceof Response) {
            return ['status' => LinkStatus::UNKNOWN, 'http_status' => null];
        }

        $status = match (true) {
            $get->successful() => LinkStatus::OK,
            in_array($get->status(), [404, 410], true) => LinkStatus::DEAD,
            default => LinkStatus::UNKNOWN,
        };

        return ['status' => $status, 'http_status' => $get->status()];
    }

    /**
     * The final response after following redirects by hand; null on a
     * connection error; false when a hop is refused or the chain too long.
     */
    protected function follow(string $method, string $url): Response|false|null
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $pin = $this->pin($url);

            if ($pin === null) {
                return false;
            }

            try {
                $response = $this->request($method, $url, $pin);
            } catch (ConnectionException) {
                return null;
            }

            if (! $response->redirect()) {
                return $response;
            }

            $next = $this->absolute((string) $response->header('Location'), $url);

            if ($next === null) {
                return false;
            }

            $url = $next;
        }

        return false;
    }

    /**
     * The CURLOPT_RESOLVE entry pinning the host to a checked public
     * address, or null when the URL must not be fetched.
     */
    protected function pin(string $url): ?string
    {
        if (! Platforms::isWebUrl($url)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $port = (int) ($parts['port'] ?? (strtolower((string) ($parts['scheme'] ?? '')) === 'http' ? 80 : 443));

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! IpGuard::isPublic($ip)) {
                return null;
            }
        }

        $ip = str_contains($ips[0], ':') ? '['.$ips[0].']' : $ips[0];

        return "{$host}:{$port}:{$ip}";
    }

    protected function absolute(string $location, string $base): ?string
    {
        $location = trim($location);

        if ($location === '') {
            return null;
        }

        if (preg_match('~^https?://~i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = preg_replace('~/[^/]*$~', '/', (string) ($parts['path'] ?? '/'));

        return $origin.$dir.$location;
    }

    protected function request(string $method, string $url, string $pin): Response
    {
        $this->pace((string) parse_url($url, PHP_URL_HOST));

        $client = Http::timeout((int) config('smartlinks.check.timeout', 10))
            ->withUserAgent((string) config('smartlinks.check.user_agent', 'Mozilla/5.0 (compatible; statamic-smartlinks link check)'))
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => [$pin]],
            ]);

        return $method === 'head' ? $client->head($url) : $client->get($url);
    }

    protected function pace(string $host): void
    {
        $interval = (int) config('smartlinks.check.per_host_ms', 1000);
        $last = $this->lastByHost[$host] ?? null;

        if ($interval > 0 && $last !== null) {
            $wait = (int) round($interval - (microtime(true) - $last) * 1000);

            if ($wait > 0) {
                Sleep::for($wait)->milliseconds();
            }
        }

        $this->lastByHost[$host] = microtime(true);
    }
}
