<script setup>
import { Head } from '@statamic/cms/inertia';
import { Description, DropdownItem, EmptyStateItem, EmptyStateMenu, Header, Icon, Listing } from '@statamic/cms/ui';

defineProps({
    setupRequired: { type: Boolean, default: false },
    hasSongs: { type: Boolean, default: false },
    listingUrl: { type: String, required: true },
    initialColumns: { type: Array, required: true },
    days: { type: Number, default: 30 },
});

const docsUrl = 'https://docs.adriangoldner.dev/smartlinks/';
</script>

<template>
    <Head :title="__('smartlinks::cp.title')" />

    <div class="max-w-page mx-auto">
        <template v-if="setupRequired || !hasSongs">
            <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="link" class="size-5 text-gray-500" />{{ __('smartlinks::cp.title') }}
                </h1>
            </header>

            <EmptyStateMenu v-if="setupRequired" :heading="__('smartlinks::cp.setup_heading')">
                <EmptyStateItem
                    icon="link"
                    :heading="__('smartlinks::cp.setup_migrate_heading')"
                    :description="__('smartlinks::cp.setup_migrate_description')"
                    :href="docsUrl"
                    target="_blank"
                />
            </EmptyStateMenu>

            <EmptyStateMenu v-else :heading="__('smartlinks::cp.empty_heading')">
                <EmptyStateItem
                    icon="link"
                    :heading="__('smartlinks::cp.title')"
                    :description="__('smartlinks::cp.empty_description')"
                    :href="docsUrl"
                    target="_blank"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('smartlinks::cp.title')" icon="link" />

            <Description class="mb-3" :text="__('smartlinks::cp.period', { days })" />

            <!-- Server mode, like core's Entries: paginator footer ("1–3 of 3"), per-page, search. -->
            <Listing
                :url="listingUrl"
                :columns="initialColumns"
                preferences-prefix="smartlinks.index"
                sort-column="total"
                sort-direction="desc"
                show-pagination-totals
            >
                <template #cell-title="{ row }">
                    <!-- Core's title cell (entries/Listing.vue): text-sm, clamped to two lines.
                         The wrapper's min width keeps the numeric columns from squeezing
                         the title to one word per line on a phone; the link stays inline.
                         Inline style: the bundle ships no CSS, so only classes core's own
                         stylesheet already contains would apply. -->
                    <div style="min-width: 7rem">
                        <a :href="row.edit_url" class="title-index-field">{{ row.title }}</a>
                    </div>
                </template>

                <template #prepended-row-actions="{ row }">
                    <DropdownItem :text="__('smartlinks::cp.edit')" icon="edit" :href="row.edit_url" />
                    <DropdownItem
                        v-if="row.landing_url"
                        :text="__('smartlinks::cp.open_page')"
                        icon="external-link"
                        :href="row.landing_url"
                        target="_blank"
                    />
                </template>
            </Listing>
        </template>
    </div>
</template>
