<?php

namespace Goldnead\Smartlinks\Listeners;

use Goldnead\Smartlinks\LinkCleaner;
use Goldnead\Smartlinks\LinkStatus;
use Goldnead\Smartlinks\Smartlinks;
use Statamic\Entries\Entry;
use Statamic\Events\EntrySaving;

/**
 * Cleans the links of a song before it is saved (`smartlinks.cleanup.on_save`),
 * whichever fieldtype the site uses for them. Only the entry's own value: a
 * localisation that inherits its links is left inheriting.
 */
class CleanLinksOnSave
{
    public function __construct(
        protected Smartlinks $smartlinks,
        protected LinkCleaner $cleaner,
        protected LinkStatus $statuses,
    ) {}

    public function handle(EntrySaving $event): void
    {
        $entry = $event->entry;

        if (! config('smartlinks.cleanup.on_save', true) || ! $entry instanceof Entry || ! $this->smartlinks->handles($entry)) {
            return;
        }

        $field = (string) config('smartlinks.field', 'streaming_links');

        if (! $entry->has($field)) {
            return;
        }

        [$rows, $changed, $renamed] = $this->cleaner->cleanRows($entry->get($field), (string) config('smartlinks.url_key', 'url'));

        if ($changed > 0) {
            $entry->set($field, $rows);
        }

        // Check history follows a rewritten URL; rows of removed links go.
        if ($entry->id() !== null) {
            $this->statuses->sync((string) $entry->id(), $renamed, $this->smartlinks->storedUrls($entry));
        }
    }
}
