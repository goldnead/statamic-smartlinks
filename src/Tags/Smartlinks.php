<?php

namespace Goldnead\Smartlinks\Tags;

use Goldnead\Smartlinks\Link;
use Goldnead\Smartlinks\Smartlinks as SmartlinksService;
use Statamic\Entries\Entry;
use Statamic\Tags\Tags;

/**
 * {{ smartlinks:links entry="…" }} … {{ /smartlinks:links }}
 *     platform, url, label, icon, click_url per link
 * {{ smartlinks:url entry="…" platform="spotify" }}
 * {{ smartlinks:page entry="…" }}   the landing page URL
 *
 * `entry` is an entry ID or slug, or an entry object; without it the entry
 * of the current context.
 */
class Smartlinks extends Tags
{
    protected static $handle = 'smartlinks';

    /**
     * @return list<array<string, mixed>>
     */
    public function links(): array
    {
        $entry = $this->entry();

        if ($entry === null) {
            return [];
        }

        return array_map(fn (Link $link) => $link->toArray(), $this->service()->links($entry));
    }

    public function url(): ?string
    {
        $entry = $this->entry();
        $platform = (string) $this->params->get('platform', '');

        return $entry && $platform !== '' ? $this->service()->url($entry, $platform) : null;
    }

    public function page(): ?string
    {
        $entry = $this->entry();

        return $entry ? $this->service()->landingUrl($entry) : null;
    }

    protected function entry(): ?Entry
    {
        $value = $this->params->get('entry') ?? $this->context->get('id');

        if ($value instanceof Entry) {
            return $this->service()->handles($value) ? $value : null;
        }

        $value = is_object($value) && method_exists($value, 'value') ? $value->value() : $value;

        if ($value instanceof Entry) {
            return $this->service()->handles($value) ? $value : null;
        }

        return is_string($value) && $value !== '' ? $this->service()->findEntry($value) : null;
    }

    protected function service(): SmartlinksService
    {
        return app(SmartlinksService::class);
    }
}
