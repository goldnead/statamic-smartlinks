<?php

namespace Goldnead\Smartlinks\Http\Controllers;

use Goldnead\Smartlinks\Link;
use Goldnead\Smartlinks\Smartlinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class SmartlinkController extends Controller
{
    public function __construct(protected Smartlinks $smartlinks) {}

    /**
     * The landing page: one button per platform the song is on.
     */
    public function show(string $slug): Response
    {
        return $this->showIn('', $slug);
    }

    /**
     * The same for a collection under a segment (`/hoeren/release/{slug}`).
     * Only that segment's collections are asked.
     */
    public function showIn(string $segment, string $slug): Response
    {
        $this->abortUnlessEnabled();

        $entry = $this->smartlinks->findBySlug($slug, $segment) ?? throw new NotFoundHttpException;
        $links = $this->smartlinks->links($entry);

        return response()->view((string) config('smartlinks.routes.view', 'smartlinks::landing'), [
            'entry' => $entry,
            'title' => (string) $entry->value('title'),
            'links' => array_map(fn (Link $link) => $link->toArray(), $links),
        ])->header('X-Robots-Tag', 'noindex');
    }

    /**
     * 302 to the URL stored on the entry for this platform, and count it.
     *
     * The location only ever comes from the entry. A slug that is not a song,
     * or a platform the song has no link for, is a 404; nothing from the
     * request is echoed into the redirect.
     */
    public function go(Request $request, string $slug, string $platform): RedirectResponse
    {
        return $this->goIn($request, '', $slug, $platform);
    }

    public function goIn(Request $request, string $segment, string $slug, string $platform): RedirectResponse
    {
        $this->abortUnlessEnabled();

        $entry = $this->smartlinks->findBySlug($slug, $segment) ?? throw new NotFoundHttpException;
        $url = $this->smartlinks->url($entry, $platform) ?? throw new NotFoundHttpException;

        if ($this->shouldCount($request) && $this->smartlinks->withinCountLimit($request, $entry, $platform)) {
            try {
                $this->smartlinks->recordClick($entry, $platform);
            } catch (Throwable $e) {
                // The listener still gets where they wanted to go; the lost
                // click is logged, not swallowed.
                Log::warning('smartlinks: click not recorded', [
                    'reason' => 'click_not_recorded',
                    'entry' => $entry->id(),
                    'platform' => $platform,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->away($url, 302)->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    protected function shouldCount(Request $request): bool
    {
        return config('smartlinks.clicks.enabled', true)
            && ! $request->isMethod('HEAD')
            && ! $this->smartlinks->isPrefetch($request)
            && ! $this->smartlinks->isBot($request->userAgent());
    }

    /**
     * Checked here as well as in routes/web.php, so a route cache built while
     * the switch was on cannot keep the pages open.
     */
    protected function abortUnlessEnabled(): void
    {
        if (! $this->smartlinks->routesEnabled()) {
            throw new NotFoundHttpException;
        }
    }
}
