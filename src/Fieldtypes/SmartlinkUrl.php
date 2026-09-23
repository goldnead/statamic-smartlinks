<?php

namespace Goldnead\Smartlinks\Fieldtypes;

use Goldnead\Smartlinks\Platforms;
use Statamic\Fields\Fieldtype;

/**
 * A streaming URL input that shows, next to the URL, which platform its host
 * belongs to. The platform is never stored: it is derived again wherever the
 * link is read, so it cannot drift from the URL.
 *
 * Use it for the URL column of the links grid.
 */
class SmartlinkUrl extends Fieldtype
{
    protected static $handle = 'smartlink_url';

    protected $categories = ['special'];

    protected $icon = 'link';

    public function process($data)
    {
        $data = is_string($data) ? trim($data) : $data;

        return $data === '' ? null : $data;
    }

    public function preProcess($data)
    {
        return is_string($data) ? $data : null;
    }

    /**
     * The host table and labels, so the browser detects exactly as PHP does.
     *
     * @return array{hosts: array<string, string>, labels: array<string, string>}
     */
    public function preload(): array
    {
        $platforms = app(Platforms::class);
        $labels = [Platforms::OTHER => $platforms->label(Platforms::OTHER)];

        foreach (array_unique($platforms->hosts()) as $platform) {
            $labels[$platform] = $platforms->label($platform);
        }

        return [
            'hosts' => $platforms->hosts(),
            'labels' => $labels,
        ];
    }

    public function augment($value)
    {
        return $value;
    }

    public function rules(): array
    {
        return ['nullable', 'url:http,https'];
    }
}
