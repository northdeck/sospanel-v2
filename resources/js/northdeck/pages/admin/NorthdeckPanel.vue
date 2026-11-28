<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import Tile from '@/components/workspace/Tile.vue';

type Field = { value: string | null; env: string | null; saved: boolean };
type FieldName =
    | 'name'
    | 'support_email'
    | 'security_email'
    | 'mail_from_address'
    | 'mail_from_name'
    | 'support_url'
    | 'chat_url';

const props = defineProps<{
    settings: Record<FieldName, Field>;
}>();

// A field holds only what was saved here; the .env value is its placeholder,
// so saving never copies .env values over.
const savedValue = (field: Field): string =>
    field.saved ? (field.value ?? '') : '';

const form = useForm<Record<FieldName, string>>({
    name: savedValue(props.settings.name),
    support_email: savedValue(props.settings.support_email),
    security_email: savedValue(props.settings.security_email),
    mail_from_address: savedValue(props.settings.mail_from_address),
    mail_from_name: savedValue(props.settings.mail_from_name),
    support_url: savedValue(props.settings.support_url),
    chat_url: savedValue(props.settings.chat_url),
});

const sections: {
    title: string;
    description: string;
    icon: 'settings-fill' | 'mail-fill' | 'help-fill';
    fields: {
        name: FieldName;
        label: string;
        hint: string;
        type: string;
        placeholder?: string;
    }[];
}[] = [
    {
        title: 'Panel',
        description:
            'What people see in the browser tab, emails and sign-in pages.',
        icon: 'settings-fill',
        fields: [
            {
                name: 'name',
                label: 'Name',
                hint: 'Shown across the panel and in emails.',
                type: 'text',
            },
        ],
    },
    {
        title: 'Email',
        description: 'Addresses the panel sends from and points people to.',
        icon: 'mail-fill',
        fields: [
            {
                name: 'mail_from_address',
                label: 'Send from',
                hint: 'The From address on every email the panel sends.',
                type: 'email',
                placeholder: 'panel@example.com',
            },
            {
                name: 'mail_from_name',
                label: 'Sender name',
                hint: 'The name next to that address.',
                type: 'text',
            },
            {
                name: 'support_email',
                label: 'Support',
                hint: 'Where people write for help. Empty uses the send-from address.',
                type: 'email',
                placeholder: 'support@example.com',
            },
            {
                name: 'security_email',
                label: 'Security reports',
                hint: 'A private address for vulnerability reports.',
                type: 'email',
                placeholder: 'security@example.com',
            },
        ],
    },
    {
        title: 'Help links',
        description: 'Where the Help page and menus send people.',
        icon: 'help-fill',
        fields: [
            {
                name: 'support_url',
                label: 'Help desk or docs',
                hint: 'A page people can open for help.',
                type: 'url',
                placeholder: 'https://',
            },
            {
                name: 'chat_url',
                label: 'Community chat',
                hint: 'Empty hides the chat link.',
                type: 'url',
                placeholder: 'https://',
            },
        ],
    },
];

function save(): void {
    form.put('/admin/panel', { preserveScroll: true });
}
</script>

<template>
    <Head :title="$t('Panel settings')" />

    <form
        class="mx-auto flex max-w-4xl flex-col gap-6 px-6 py-8"
        @submit.prevent="save"
    >
        <div class="flex flex-col gap-1">
            <h1 class="text-[18px]/6 font-semibold">
                {{ $t('Panel settings') }}
            </h1>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        'Empty fields use the values in .env, shown in grey. What you save here goes over them.',
                    )
                }}
            </p>
        </div>

        <Tile
            v-for="section in sections"
            :key="section.title"
            :icon="section.icon"
            :title="$t(section.title)"
            :description="$t(section.description)"
            :padded="false"
        >
            <div class="ds-tile-rows">
                <SettingsLine
                    v-for="field in section.fields"
                    :key="field.name"
                    :label="$t(field.label)"
                    :hint="$t(field.hint)"
                    :input-id="`panel-${field.name}`"
                >
                    <Input
                        :id="`panel-${field.name}`"
                        v-model="form[field.name]"
                        :type="field.type"
                        :placeholder="
                            settings[field.name].env ?? field.placeholder ?? ''
                        "
                    />
                    <p
                        v-if="
                            settings[field.name].saved &&
                            settings[field.name].env
                        "
                        class="text-[12px]/4 text-(--ds-text-faint)"
                    >
                        {{
                            $t('Empty goes back to .env: :value', {
                                value: settings[field.name].env ?? '',
                            })
                        }}
                    </p>
                    <InputError :message="form.errors[field.name]" />
                </SettingsLine>
            </div>
        </Tile>

        <div class="flex justify-end">
            <AppButton
                type="submit"
                variant="primary"
                :disabled="form.processing"
            >
                {{ $t('Save') }}
            </AppButton>
        </div>
    </form>
</template>
