<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import type { Component } from 'vue';
import { computed } from 'vue';
import ExtensionSlot from '@/components/ExtensionSlot.vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import SettingsItemRow from '@/components/workspace/settings/SettingsItemRow.vue';
import { useAssistant } from '@/composables/useAssistant';
import { useWhatsNew } from '@/composables/useWhatsNew';
import { docsUrl } from '@/lib/docs';
import type { Workspace } from '@/types';

defineOptions({ inheritAttrs: false });

defineProps<{ workspace: Workspace }>();

const slotParts = import.meta.glob<Component>(
    [
        '../../../../vendor/serveroshq/*/resources/js/slots/workspace/Help/**/*.vue',
        '../../northdeck/slots/workspace/Help/**/*.vue', // NORTHDECK: support row
    ],
    { eager: true, import: 'default' },
);

const fromPackage = Object.keys(slotParts).some((path) =>
    path.includes('/resources/js/slots/workspace/Help/main/'),
);

const page = usePage<{ changelog?: { unread: number } | null }>();
const assistant = useAssistant();
const whatsNew = useWhatsNew();

const docs = computed(() => docsUrl(page.props.docsUrl));
const source = computed(() => page.props.brand?.source ?? null);
const issues = computed(() =>
    source.value &&
    /^https:\/\/github\.com\/[^/]+\/[^/]+\/?$/.test(source.value)
        ? `${source.value.replace(/\/$/, '')}/issues`
        : source.value,
);
</script>

<template>
    <Head :title="$t('Help')" />

    <div v-if="fromPackage" class="h-full min-h-0">
        <ExtensionSlot name="workspace/Help/main" :parts="slotParts" />
    </div>

    <template v-else>
        <DashHeader :crumbs="[{ label: $t('Help') }]" />

        <div
            class="mx-auto flex w-full max-w-[720px] flex-col px-5 pt-5 pb-16 font-ui sm:px-10 lg:pt-9"
        >
            <header class="flex flex-col gap-1 pb-6">
                <h1
                    class="text-[20px]/7 font-medium tracking-[-0.015em] text-fg-primary"
                >
                    {{ $t('Help') }}
                </h1>
                <p class="text-[13px]/5 tracking-[-0.005em] text-fg-tertiary">
                    {{
                        $t(
                            'Stuck on something? Ask AI, read the docs or ask the people who make ServerOS.',
                        )
                    }}
                </p>
            </header>

            <div class="dash-card flex flex-col">
                <SettingsItemRow
                    :title="$t('Ask AI')"
                    :description="
                        $t(
                            'Ask about your machines and services, or how something in ServerOS works.',
                        )
                    "
                >
                    <template #icon>
                        <FilledIcon
                            name="sparkle-fill"
                            class="text-fg-tertiary"
                        />
                    </template>
                    <template #actions>
                        <DashButton
                            :label="$t('Ask AI')"
                            @click="assistant.toggle()"
                        />
                    </template>
                </SettingsItemRow>

                <SettingsItemRow
                    :title="$t('Docs')"
                    :description="
                        $t(
                            'Guides for connecting machines, deploying and everything after.',
                        )
                    "
                >
                    <template #icon>
                        <FilledIcon name="docs-fill" class="text-fg-tertiary" />
                    </template>
                    <template #actions>
                        <DashButton
                            :href="docs"
                            external
                            :label="$t('Open docs')"
                        />
                    </template>
                </SettingsItemRow>

                <SettingsItemRow
                    v-if="page.props.changelog"
                    :title="$t('What’s new')"
                    :description="
                        whatsNew.unread.value
                            ? $t(':count updates you haven’t seen.', {
                                  count: String(whatsNew.unread.value),
                              })
                            : $t('What changed in ServerOS lately.')
                    "
                >
                    <template #icon>
                        <FilledIcon name="history" class="text-fg-tertiary" />
                    </template>
                    <template #actions>
                        <DashButton
                            :label="$t('See what’s new')"
                            @click="whatsNew.show()"
                        />
                    </template>
                </SettingsItemRow>

                <ExtensionSlot
                    name="workspace/Help/rows"
                    :parts="slotParts"
                /><!-- NORTHDECK -->

                <SettingsItemRow
                    v-if="issues"
                    :title="$t('Report a bug')"
                    :description="
                        $t(
                            'ServerOS is open source. Bugs and questions go to its issues on GitHub.',
                        )
                    "
                >
                    <template #icon>
                        <BrandLogo name="github" />
                    </template>
                    <template #actions>
                        <DashButton
                            :href="issues"
                            external
                            :label="$t('Open issues')"
                        />
                    </template>
                </SettingsItemRow>

                <SettingsItemRow
                    v-if="page.props.feedbackUrl"
                    :title="$t('Ideas')"
                    :description="
                        $t('Suggest a feature, or vote for what comes next.')
                    "
                >
                    <template #icon>
                        <FilledIcon name="plus-fill" class="text-fg-tertiary" />
                    </template>
                    <template #actions>
                        <DashButton
                            :href="`${page.props.feedbackUrl}/`"
                            external
                            :label="$t('Share an idea')"
                        />
                    </template>
                </SettingsItemRow>

                <SettingsItemRow
                    v-if="page.props.statusUrl"
                    :title="$t('ServerOS status')"
                    :description="
                        $t('Whether ServerOS itself is up right now.')
                    "
                >
                    <template #icon>
                        <FilledIcon
                            name="monitors-fill"
                            class="text-fg-tertiary"
                        />
                    </template>
                    <template #actions>
                        <DashButton
                            :href="page.props.statusUrl"
                            external
                            :label="$t('Open status')"
                        />
                    </template>
                </SettingsItemRow>
            </div>
        </div>
    </template>
</template>
