<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import { login } from '@/routes';

defineOptions({
    layout: {
        title: trans('Sign in with SSO'),
        description: trans(
            'Use your work email. We send you to your company’s sign-in.',
        ),
    },
});

defineProps<{
    email: string;
    error?: string | null;
    workspace?: string | null;
    connections?: { name: string; url: string }[];
}>();
</script>

<template>
    <Head :title="$t('Sign in with SSO')" />

    <div
        v-if="error"
        role="alert"
        class="mb-4 rounded-lg bg-(--ds-red)/10 px-3 py-2 text-center text-[13px] font-medium text-(--ds-red)"
    >
        {{ error }}
    </div>

    <div v-if="connections?.length" class="grid gap-2">
        <p
            v-if="workspace"
            class="text-center text-[13px]/5 text-(--ds-text-muted)"
        >
            {{ $t('Pick how to sign in to :workspace.', { workspace }) }}
        </p>
        <a
            v-for="c in connections"
            :key="c.url"
            :href="c.url"
            :class="appButtonClasses('secondary', 'md', 'w-full')"
            >{{ c.name }}</a
        >
    </div>

    <Form
        v-else
        action="/sso"
        method="post"
        class="flex flex-col gap-6"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-2">
            <Label for="sso-email">{{ $t('Work email') }}</Label>
            <Input
                id="sso-email"
                type="email"
                name="email"
                required
                autofocus
                autocomplete="email"
                :default-value="email"
                :placeholder="$t('email@example.com')"
            />
            <InputError :message="errors.email" />
        </div>
        <button
            type="submit"
            :class="appButtonClasses('primary', 'md', 'w-full')"
            :disabled="processing"
        >
            <Spinner v-if="processing" />
            {{ $t('Continue') }}
        </button>
    </Form>

    <p class="mt-6 text-center text-[13px]/5 text-(--ds-text-muted)">
        <Link :href="login()" class="underline-offset-4 hover:underline">
            {{ $t('Sign in another way') }}
        </Link>
    </p>
</template>
