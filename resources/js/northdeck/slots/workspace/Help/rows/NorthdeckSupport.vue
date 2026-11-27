<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import SettingsItemRow from '@/components/workspace/settings/SettingsItemRow.vue';

const page = usePage<{
    northdeck?: { support: { email: string | null; url: string | null } };
}>();

const support = computed(() => page.props.northdeck?.support ?? null);
</script>

<template>
    <SettingsItemRow
        v-if="support && (support.url || support.email)"
        :title="$t('Northdeck support')"
        :description="
            $t('Questions about this panel or your servers go to Northdeck.')
        "
    >
        <template #icon>
            <FilledIcon name="docs-fill" class="text-fg-tertiary" />
        </template>
        <template #actions>
            <DashButton
                v-if="support.url"
                :href="support.url"
                external
                :label="$t('Get help')"
            />
            <DashButton
                v-else-if="support.email"
                :href="`mailto:${support.email}`"
                external
                :label="$t('Email support')"
            />
        </template>
    </SettingsItemRow>
</template>
