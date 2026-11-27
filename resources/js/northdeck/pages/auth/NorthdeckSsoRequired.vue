<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { appButtonClasses } from '@/lib/appButton';
import { logout } from '@/routes';

defineProps<{
    workspace: string;
    email: string;
    signInUrl: string;
}>();

defineOptions({
    layout: {
        title: '',
    },
});
</script>

<template>
    <Head :title="$t('Sign in with SSO')" />

    <div class="flex flex-col gap-5 text-center">
        <div class="flex flex-col gap-1.5">
            <h2 class="text-[15px]/5 font-medium text-(--ds-text)">
                {{ $t('Sign in with SSO to open :workspace', { workspace }) }}
            </h2>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(':workspace asks you to sign in with SSO.', {
                        workspace,
                    })
                }}
            </p>
            <p class="text-[13px]/5 text-(--ds-text-faint)">
                {{ $t('You’re signed in as :email.', { email }) }}
            </p>
        </div>

        <a
            :href="signInUrl"
            :class="appButtonClasses('primary', 'md', 'w-full')"
        >
            {{ $t('Sign in with SSO') }}
        </a>

        <Link
            :href="logout()"
            as="button"
            class="cursor-pointer text-[13px]/4 text-(--ds-text-muted) underline-offset-4 hover:underline"
        >
            {{ $t('Sign out') }}
        </Link>
    </div>
</template>
