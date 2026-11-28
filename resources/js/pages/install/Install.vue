<script setup lang="ts">
import NorthdeckLogo from '@/northdeck/components/NorthdeckLogo.vue'; // NORTHDECK
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import AddressStep from '@/components/install/AddressStep.vue';
import CheckStep from '@/components/install/CheckStep.vue';
import CodeStep from '@/components/install/CodeStep.vue';
import DatabaseStep from '@/components/install/DatabaseStep.vue';
import DoneStep from '@/components/install/DoneStep.vue';
import MailStep from '@/components/install/MailStep.vue';
import OwnerStep from '@/components/install/OwnerStep.vue';
import type {
    AddressProps,
    CheckItem,
    DatabaseProps,
    InstallStep,
    MailProps,
    OwnerProps,
    Summary,
} from '@/components/install/types';

const props = defineProps<{
    step: InstallStep;
    current: InstallStep;
    progress: Record<InstallStep, boolean>;
    docker: boolean;
    codeCommand: string;
    guideUrl: string;
    checks?: CheckItem[];
    database?: DatabaseProps;
    address?: AddressProps;
    mail?: MailProps;
    owner?: OwnerProps;
    summary?: Summary;
}>();

const steps = computed<
    { key: InstallStep; label: string; icon: FilledIconName }[]
>(() => [
    { key: 'check', label: trans('Server'), icon: 'machines-fill' },
    { key: 'database', label: trans('Database'), icon: 'database-fill' },
    { key: 'address', label: trans('Address'), icon: 'globe-fill' },
    { key: 'mail', label: trans('Email'), icon: 'mail-fill' },
    { key: 'owner', label: trans('Account'), icon: 'user-fill' },
    { key: 'done', label: trans('Finish'), icon: 'list-checks' },
]);

const index = computed(() =>
    steps.value.findIndex((step) => step.key === props.step),
);
const header = computed(() =>
    props.step === 'code'
        ? { label: trans('Setup code'), icon: 'key-fill' as const }
        : steps.value[index.value],
);
</script>

<template>
    <Head :title="$t('Set up ServerOS')" />
    <div
        class="ds-root flex min-h-svh flex-col bg-surface-main font-ui text-fg-primary antialiased"
    >
        <main
            class="flex grow flex-col items-center justify-center gap-[22px] px-4 py-12"
        >
            <!-- NORTHDECK: the Northdeck wordmark -->
            <NorthdeckLogo :height="22" />

            <DashTray
                :icon="header.icon"
                :title="header.label"
                class="w-full max-w-[500px]"
                panel-class="gap-[22px] px-5 pt-[22px] pb-6 sm:px-7 sm:pt-[26px] sm:pb-7"
            >
                <template #actions>
                    <span
                        v-if="step === 'code'"
                        class="text-[12px]/4 text-fg-muted"
                        >{{ $t('Self-hosted') }}</span
                    >
                    <template v-else>
                        <span class="flex gap-[3px]" aria-hidden="true">
                            <span
                                v-for="(item, position) in steps"
                                :key="item.key"
                                class="h-[3px] w-3.5 rounded-[1.5px] transition-colors duration-300"
                                :class="
                                    position <= index
                                        ? 'bg-dash-blue'
                                        : 'bg-edge-tile'
                                "
                            />
                        </span>
                        <span
                            class="text-[12px]/4 whitespace-nowrap text-fg-muted"
                        >
                            {{
                                $t(':step of :total', {
                                    step: String(index + 1),
                                    total: String(steps.length),
                                })
                            }}
                        </span>
                    </template>
                </template>

                <CodeStep
                    v-if="step === 'code'"
                    :command="codeCommand"
                    :docker="docker"
                />
                <CheckStep
                    v-else-if="step === 'check'"
                    :checks="checks ?? []"
                />
                <DatabaseStep
                    v-else-if="step === 'database' && database"
                    :database="database"
                    :docker="docker"
                />
                <AddressStep
                    v-else-if="step === 'address' && address"
                    :address="address"
                    :docker="docker"
                />
                <MailStep
                    v-else-if="step === 'mail' && mail"
                    :mail="mail"
                    :docker="docker"
                />
                <OwnerStep
                    v-else-if="step === 'owner' && owner"
                    :owner="owner"
                />
                <DoneStep
                    v-else-if="step === 'done' && summary"
                    :summary="summary"
                />
            </DashTray>

            <a
                :href="guideUrl"
                target="_blank"
                rel="noopener"
                class="flex items-center gap-[7px] rounded-[4px] text-[12.5px]/4 text-fg-muted transition-colors hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
            >
                <FilledIcon name="docs-fill" :size="13" />
                {{ $t('Self-hosting guide') }}
            </a>
        </main>
    </div>
</template>
