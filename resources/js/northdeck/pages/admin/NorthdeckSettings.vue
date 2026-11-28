<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import Tile from '@/components/workspace/Tile.vue';

type Field = {
    key: string;
    env: string;
    label: string;
    hint: string;
    type:
        | 'text'
        | 'email'
        | 'url'
        | 'int'
        | 'bool'
        | 'select'
        | 'list'
        | 'secret';
    options: Record<string, string> | null;
    saved: boolean;
    value: string | null;
    envValue: string | null;
    envSet: boolean;
};

type Group = {
    key: string;
    title: string;
    description: string;
    icon: FilledIconName;
    fields: Field[];
};

const props = defineProps<{
    groups: Group[];
    notManaged: { keys: string; why: string }[];
}>();

// A field holds only what was saved here. Empty means "use .env", whose
// value shows as the placeholder, so saving never copies .env values over.
const initial: Record<string, string> = {};
for (const group of props.groups) {
    for (const field of group.fields) {
        initial[field.key] = field.saved && field.value ? field.value : '';
    }
}

const form = useForm<{ values: Record<string, string>; clear: string[] }>({
    values: { ...initial },
    clear: [],
});

const shown = ref<string>(props.groups[0]?.key ?? '');

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

function envText(field: Field): string {
    if (field.type === 'secret') {
        return field.envSet ? trans('set in .env') : trans('not set');
    }
    if (field.envValue === null) {
        return trans('not set');
    }
    if (field.type === 'bool') {
        return field.envValue === 'on' ? trans('on') : trans('off');
    }
    if (field.type === 'select') {
        return trans(field.options?.[field.envValue] ?? field.envValue);
    }

    return field.envValue;
}

function secretPlaceholder(field: Field): string {
    if (form.clear.includes(field.key))
        return trans('Goes back to .env on save');
    if (field.saved) return trans('Saved. Type to replace it.');
    return field.envSet
        ? trans('Set in .env. Type to replace it.')
        : trans('Not set');
}

function toggleClear(field: Field): void {
    form.clear = form.clear.includes(field.key)
        ? form.clear.filter((k) => k !== field.key)
        : [...form.clear, field.key];
    form.values[field.key] = '';
}

function inputType(field: Field): string {
    return (
        {
            email: 'email',
            url: 'url',
            int: 'number',
            secret: 'password',
        }[field.type as string] ?? 'text'
    );
}

function save(): void {
    form.put('/admin/settings', {
        preserveScroll: true,
        onSuccess: () => {
            form.clear = [];
            for (const group of props.groups) {
                for (const field of group.fields) {
                    if (field.type === 'secret') form.values[field.key] = '';
                }
            }
        },
    });
}

const errorFor = (key: string): string | undefined =>
    (form.errors as Record<string, string | undefined>)[`values.${key}`];
</script>

<template>
    <Head :title="$t('Settings')" />

    <form
        class="mx-auto flex max-w-4xl flex-col gap-6 px-6 py-8"
        @submit.prevent="save"
    >
        <div class="flex flex-col gap-1">
            <h1 class="text-[18px]/6 font-semibold">{{ $t('Settings') }}</h1>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        'The panel’s .env settings. An empty field uses .env, shown in grey; what you save here goes over it. Changes apply right away.',
                    )
                }}
            </p>
        </div>

        <nav class="flex flex-wrap gap-1" :aria-label="$t('Sections')">
            <button
                v-for="group in groups"
                :key="group.key"
                type="button"
                class="cursor-pointer rounded-[7px] px-2.5 py-1 text-[13px]/5"
                :class="
                    shown === group.key
                        ? 'bg-(--ds-hover) font-medium'
                        : 'text-(--ds-text-muted) hover:bg-(--ds-hover)'
                "
                @click="shown = group.key"
            >
                {{ $t(group.title) }}
            </button>
            <button
                type="button"
                class="cursor-pointer rounded-[7px] px-2.5 py-1 text-[13px]/5"
                :class="
                    shown === 'env-only'
                        ? 'bg-(--ds-hover) font-medium'
                        : 'text-(--ds-text-muted) hover:bg-(--ds-hover)'
                "
                @click="shown = 'env-only'"
            >
                {{ $t('Only in .env') }}
            </button>
        </nav>

        <template v-for="group in groups" :key="group.key">
            <Tile
                v-show="shown === group.key"
                :icon="group.icon"
                :title="$t(group.title)"
                :description="$t(group.description)"
                :padded="false"
            >
                <div class="ds-tile-rows">
                    <SettingsLine
                        v-for="field in group.fields"
                        :key="field.key"
                        :label="$t(field.label)"
                        :hint="field.hint ? $t(field.hint) : undefined"
                        :input-id="`setting-${field.key}`"
                    >
                        <select
                            v-if="field.type === 'bool'"
                            :id="`setting-${field.key}`"
                            v-model="form.values[field.key]"
                            :class="selectClass"
                        >
                            <option value="">
                                {{
                                    $t('Use .env (:value)', {
                                        value: envText(field),
                                    })
                                }}
                            </option>
                            <option value="on">{{ $t('On') }}</option>
                            <option value="off">{{ $t('Off') }}</option>
                        </select>

                        <select
                            v-else-if="field.type === 'select'"
                            :id="`setting-${field.key}`"
                            v-model="form.values[field.key]"
                            :class="selectClass"
                        >
                            <option value="">
                                {{
                                    $t('Use .env (:value)', {
                                        value: envText(field),
                                    })
                                }}
                            </option>
                            <option
                                v-for="(label, value) in field.options ?? {}"
                                :key="value"
                                :value="value"
                            >
                                {{ $t(label) }}
                            </option>
                        </select>

                        <div
                            v-else-if="field.type === 'secret'"
                            class="flex items-center gap-2"
                        >
                            <Input
                                :id="`setting-${field.key}`"
                                v-model="form.values[field.key]"
                                type="password"
                                autocomplete="new-password"
                                :disabled="form.clear.includes(field.key)"
                                :placeholder="secretPlaceholder(field)"
                            />
                            <AppButton
                                v-if="field.saved"
                                size="sm"
                                @click="toggleClear(field)"
                            >
                                {{
                                    form.clear.includes(field.key)
                                        ? $t('Keep')
                                        : $t('Clear')
                                }}
                            </AppButton>
                        </div>

                        <Input
                            v-else
                            :id="`setting-${field.key}`"
                            v-model="form.values[field.key]"
                            :type="inputType(field)"
                            :placeholder="field.envValue ?? ''"
                        />

                        <p class="text-[12px]/4 text-(--ds-text-faint)">
                            <code class="font-ui-mono">{{ field.env }}</code>
                            <template v-if="field.saved">
                                ·
                                {{
                                    $t('saved here; .env says :value', {
                                        value: envText(field),
                                    })
                                }}
                            </template>
                        </p>
                        <InputError :message="errorFor(field.key)" />
                    </SettingsLine>
                </div>
            </Tile>
        </template>

        <Tile
            v-show="shown === 'env-only'"
            icon="lock-fill"
            :title="$t('Only in .env')"
            :description="
                $t(
                    'These can’t change while the panel runs. Edit .env on the server, then restart the panel.',
                )
            "
            :padded="false"
        >
            <div class="ds-tile-rows">
                <SettingsLine
                    v-for="item in notManaged"
                    :key="item.keys"
                    :label="item.keys"
                >
                    <p class="text-[13px]/5 text-(--ds-text-muted) md:pt-1.5">
                        {{ $t(item.why) }}
                    </p>
                </SettingsLine>
            </div>
        </Tile>

        <div v-show="shown !== 'env-only'" class="flex justify-end">
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
