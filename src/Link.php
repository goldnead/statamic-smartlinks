<?php

namespace Goldnead\Smartlinks;

/**
 * One streaming link as stored on an entry, with its derived platform.
 */
final class Link
{
    public function __construct(
        public readonly string $platform,
        public readonly string $url,
        public readonly string $label,
        public readonly ?string $clickUrl = null,
    ) {}

    /**
     * What tags and the landing view get. `icon` is the platform handle, for
     * the site's own icon set.
     *
     * @return array{platform: string, url: string, label: string, icon: string, click_url: string|null}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'url' => $this->url,
            'label' => $this->label,
            'icon' => $this->platform,
            'click_url' => $this->clickUrl,
        ];
    }
}
