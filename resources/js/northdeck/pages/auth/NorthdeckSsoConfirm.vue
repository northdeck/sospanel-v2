<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import { login } from '@/routes';

defineProps<{
    connection: string;
    email: string;
    signedInAs?: string | null;
    confirmUrl: string;
    token: string;
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
                {{ $t('Continue as :email?', { email }) }}
            </h2>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        ':connection sent a sign-in you didn’t start here. Only continue if you just picked this app at your identity provider.',
                        { connection },
                    )
                }}
            </p>
            <p
                v-if="signedInAs && signedInAs !== email"
                class="text-[13px]/5 text-(--ds-amber)"
            >
                {{
                    $t(
                        'You’re signed in as :current. Continuing switches account.',
                        {
                            current: signedInAs,
                        },
                    )
                }}
            </p>
        </div>

        <Form :action="confirmUrl" method="post" v-slot="{ processing }">
            <input type="hidden" name="token" :value="token" />
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'w-full')"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ $t('Continue as :email', { email }) }}
            </button>
        </Form>

        <Link
            :href="login()"
            class="text-[13px]/4 text-(--ds-text-muted) underline-offset-4 hover:underline"
        >
            {{ $t('Cancel') }}
        </Link>
    </div>
</template>
