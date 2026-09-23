<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Description, DropdownItem, EmptyStateItem, EmptyStateMenu, Header, Icon, Listing } from '@statamic/cms/ui';

defineProps({
    setupRequired: { type: Boolean, default: false },
    rows: { type: Array, required: true },
    initialColumns: { type: Array, required: true },
    truncated: { type: Boolean, default: false },
    days: { type: Number, default: 30 },
});

const docsUrl = 'https://docs.adriangoldner.dev/smartlinks/';

function reload() {
    router.reload();
}
</script>

<template>
    <Head :title="__('smartlinks::cp.title')" />

    <div class="max-w-page mx-auto">
        <template v-if="setupRequired || rows.length === 0">
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
            <Description v-if="truncated" class="mb-3" :text="__('smartlinks::cp.truncated', { limit: rows.length })" />

            <!-- Client mode: search and sort in the browser. -->
            <Listing
                :items="rows"
                :columns="initialColumns"
                preferences-prefix="smartlinks.index"
                sort-column="total"
                sort-direction="desc"
                @refreshing="reload"
            >
                <template #cell-title="{ row }">
                    <a :href="row.edit_url" class="font-medium">{{ row.title }}</a>
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
