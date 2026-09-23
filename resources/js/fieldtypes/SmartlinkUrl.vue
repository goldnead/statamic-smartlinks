<script setup>
import { computed } from 'vue';
import { Fieldtype } from '@statamic/cms';
import { Badge, Input } from '@statamic/cms/ui';
import { detect } from '../platforms.js';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose, update, isReadOnly } = Fieldtype.use(emit, props);
defineExpose(expose);

// The same host table PHP uses (preloaded), so the badge never disagrees
// with what the redirect and the tags decide.
const platform = computed(() => detect(props.value, props.meta?.hosts ?? {}));
const label = computed(() => (platform.value ? props.meta?.labels?.[platform.value] ?? platform.value : null));
</script>

<template>
    <div class="flex items-center gap-2">
        <Input
            class="flex-1"
            type="url"
            :model-value="value"
            :read-only="isReadOnly"
            placeholder="https://"
            @update:model-value="update"
        />
        <Badge
            v-if="label"
            pill
            :color="platform === 'other' ? 'red' : 'default'"
            :text="label"
            :title="__('smartlinks::cp.fieldtype_detected')"
        />
    </div>
</template>
