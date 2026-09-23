<?php

namespace Goldnead\Smartlinks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Asks a stored link whether it still leads somewhere: HEAD first, GET when
 * HEAD is refused or inconclusive (many shops answer HEAD with 403 or 405).
 *
 * Only a definite answer kills a link: 404 or 410, or a host that no longer
 * resolves (Napster's API domain). A 403 wall, a 429, a 5xx or a timeout is
 * `unknown`, never `dead`. Requests to one host are spaced by
 * `smartlinks.check.per_host_ms`.
 */
class LinkChecker
{
    /** @var array<string, float> host => time of the last request */
    protected array $lastByHost = [];

    /**
     * @return array{status: string, http_status: int|null}
     */
    public function check(string $url): array
    {
        if (! Platforms::isWebUrl($url)) {
            return ['status' => LinkStatus::DEAD, 'http_status' => null];
        }

        try {
            $head = $this->request('head', $url);

            if ($head->successful() || $head->redirect()) {
                return ['status' => LinkStatus::OK, 'http_status' => $head->status()];
            }
        } catch (ConnectionException $e) {
            if ($this->hostIsGone($e)) {
                return ['status' => LinkStatus::DEAD, 'http_status' => null];
            }
        }

        try {
            $get = $this->request('get', $url);
        } catch (ConnectionException $e) {
            return ['status' => $this->hostIsGone($e) ? LinkStatus::DEAD : LinkStatus::UNKNOWN, 'http_status' => null];
        }

        $status = match (true) {
            $get->successful(), $get->redirect() => LinkStatus::OK,
            in_array($get->status(), [404, 410], true) => LinkStatus::DEAD,
            default => LinkStatus::UNKNOWN,
        };

        return ['status' => $status, 'http_status' => $get->status()];
    }

    protected function request(string $method, string $url): Response
    {
        $this->pace((string) parse_url($url, PHP_URL_HOST));

        /** @var PendingRequest $client */
        $client = Http::timeout((int) config('smartlinks.check.timeout', 10))
            ->withUserAgent((string) config('smartlinks.check.user_agent', 'Mozilla/5.0 (compatible; statamic-smartlinks link check)'))
            ->withOptions(['allow_redirects' => ['max' => 5]]);

        return $method === 'head' ? $client->head($url) : $client->get($url);
    }

    protected function hostIsGone(ConnectionException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'could not resolve host');
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
