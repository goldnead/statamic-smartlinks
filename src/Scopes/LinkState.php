<?php

namespace Goldnead\Smartlinks\Scopes;

use Statamic\Query\Scopes\Filter;

/**
 * The "Smart Links" listing filter: songs with dead links, or with
 * suggestions waiting for review. Applied by the listing controller over its
 * computed rows; `apply()` only exists for core's contract.
 */
class LinkState extends Filter
{
    public $pinned = true;

    public static function title()
    {
        return __('smartlinks::cp.filter_title');
    }

    public function fieldItems()
    {
        return [
            'state' => [
                'type' => 'radio',
                'options' => [
                    'dead' => __('smartlinks::cp.filter_dead'),
                    'suggested' => __('smartlinks::cp.filter_suggested'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        //
    }

    public function badge($values)
    {
        return match ($values['state'] ?? null) {
            'dead' => __('smartlinks::cp.filter_dead'),
            'suggested' => __('smartlinks::cp.filter_suggested'),
            default => null,
        };
    }

    public function visibleTo($key)
    {
        return $key === 'smartlinks';
    }
}
