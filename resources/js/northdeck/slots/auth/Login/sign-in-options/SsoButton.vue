<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import LastUsedPill from '@/components/LastUsedPill.vue';
import { appButtonClasses } from '@/lib/appButton';
import { SolarLock as Lock } from '@/lib/solarIcons';

defineProps<{ lastUsed?: string | null }>();

const page = usePage<{
    northdeck?: {
        sso: boolean;
        ssoUrl: string | null;
        instanceSso: string | null;
    };
}>();

const sso = computed(() => page.props.northdeck);
</script>

<template>
    <div v-if="sso?.sso && sso.ssoUrl">
        <a
            :href="sso.ssoUrl"
            :class="appButtonClasses('secondary', 'md', 'w-full')"
        >
            <Lock class="size-4" />
            {{
                sso.instanceSso
                    ? $t('Sign in with :name', { name: sso.instanceSso })
                    : $t('Sign in with SSO')
            }}
            <LastUsedPill v-if="lastUsed === 'sso'" />
        </a>
    </div>
</template>
