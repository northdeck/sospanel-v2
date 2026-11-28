<script setup lang="ts">
import NorthdeckLogo from '@/northdeck/components/NorthdeckLogo.vue'; // NORTHDECK
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import { dayMonth } from '@/components/machines/access/access';
import ViewingAsFrame from '@/layouts/ViewingAsFrame.vue';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace } from '@/lib/workspaceRoute';
import accessRoutes from '@/routes/workspace/machines/access';

defineOptions({ layout: ViewingAsFrame });

const props = defineProps<{
    workspace: { name: string; slug: string };
    machine: {
        id: number;
        name: string;
        provider: string | null;
        iconUrl: string | null;
    };
    access: {
        id: number;
        state: string;
        name: string;
        email: string | null;
        avatar: string | null;
        external: boolean;
        mine: boolean;
        login: string;
        keyType: string | null;
        fingerprint: string | null;
        comment: string | null;
        note: string | null;
        requestedAt: string | null;
        expiresAt: string | null;
        decidedBy: string | null;
        decidedAt: string | null;
        error: string | null;
    };
    viewer: { email: string };
    canDecide: boolean;
    accessUrl: string;
}>();

const busy = ref<'approve' | 'decline' | null>(null);
const error = ref<string | null>(null);

const waiting = computed(() => props.access.state === 'requested');

const heading = computed(() => {
    if (waiting.value) {
        return props.canDecide
            ? trans('Approve this SSH access?')
            : trans('Waiting for approval');
    }

    return (
        {
            declined: trans('This request was turned down'),
            removed: trans('This access was removed'),
        }[props.access.state] ?? trans('This request was approved')
    );
});

const outcome = computed(() => {
    const by = props.access.decidedBy;
    const when = dayMonth(props.access.decidedAt);
    switch (props.access.state) {
        case 'requested':
            return null;
        case 'declined':
            return by
                ? trans(':name turned it down on :date.', {
                      name: by,
                      date: when,
                  })
                : trans('It was turned down.');
        case 'removed':
            return trans('The key is no longer on :name.', {
                name: props.machine.name,
            });
        case 'failed':
            return (
                props.access.error ??
                trans(':name couldn’t add the key.', {
                    name: props.machine.name,
                })
            );
        case 'active':
            return by
                ? trans(':name approved it on :date. The key is on :machine.', {
                      name: by,
                      date: when,
                      machine: props.machine.name,
                  })
                : trans('The key is on :machine.', {
                      machine: props.machine.name,
                  });
        default:
            return by
                ? trans(':name approved it. ServerOS is adding the key.', {
                      name: by,
                  })
                : trans('ServerOS is adding the key.');
    }
});

const rows = computed(() =>
    [
        {
            key: 'machine',
            label: trans('Machine'),
            value: trans(':machine in :workspace', {
                machine: props.machine.name,
                workspace: props.workspace.name,
            }),
        },
        {
            key: 'login',
            label: trans('Logs in as'),
            value: props.access.login,
            mono: true,
        },
        {
            key: 'key',
            label: trans('Key'),
            value: [props.access.keyType, props.access.comment]
                .filter(Boolean)
                .join(' · '),
        },
        {
            key: 'from',
            label: trans('Asked by'),
            value: props.access.email
                ? `${props.access.name} (${props.access.email})`
                : props.access.name,
        },
        {
            key: 'asked',
            label: trans('Asked'),
            value: capitalise(timeAgo(props.access.requestedAt)),
        },
        {
            key: 'ends',
            label: trans('Access ends'),
            value: props.access.expiresAt
                ? dayMonth(props.access.expiresAt)
                : trans('When someone removes it'),
        },
        props.access.note
            ? { key: 'note', label: trans('Note'), value: props.access.note }
            : null,
    ].filter(
        (
            row,
        ): row is {
            key: string;
            label: string;
            value: string;
            mono?: boolean;
        } => row !== null,
    ),
);

const fingerprintLines = computed(() => {
    const fingerprint = props.access.fingerprint;
    if (!fingerprint) return [trans('No key yet')];
    const half = Math.ceil(fingerprint.length / 2);

    return [fingerprint.slice(0, half), fingerprint.slice(half)];
});

function capitalise(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function decide(action: 'approve' | 'decline'): void {
    if (busy.value) return;
    busy.value = action;
    error.value = null;
    router.post(
        onWorkspace(
            accessRoutes[action]({
                workspace: props.workspace.slug,
                machine: props.machine.id,
                access: props.access.id,
            }),
        ).url,
        {},
        {
            preserveScroll: true,
            onError: (errors) =>
                (error.value =
                    Object.values(errors)[0] ??
                    trans('That didn’t work. Try again.')),
            onFinish: () => (busy.value = null),
        },
    );
}
</script>

<template>
    <Head :title="$t('SSH access')" />

    <main
        class="flex min-h-svh flex-col items-center justify-center gap-7 bg-surface-main px-4 py-10 font-ui text-fg-primary"
    >
        <div class="flex items-center gap-2.5">
            <!-- NORTHDECK: the Northdeck wordmark -->
            <NorthdeckLogo :height="20" />
        </div>

        <section
            class="flex w-full max-w-[420px] flex-col gap-[18px] rounded-[14px] border-[0.5px] border-edge-control bg-surface-canvas px-6 pt-6 pb-[22px] shadow-[0_12px_32px_-12px_rgb(0_0_0/0.4),0_1px_2px_rgb(0_0_0/0.5)]"
            :aria-labelledby="'ssh-approval-title'"
        >
            <div class="flex flex-col gap-1">
                <h1
                    id="ssh-approval-title"
                    class="text-[18px]/[26px] font-medium tracking-[-0.015em] text-fg-primary"
                >
                    {{ heading }}
                </h1>
                <p class="text-[13px]/5 tracking-[-0.005em] text-fg-tertiary">
                    {{ $t('Signed in as :email', { email: viewer.email }) }}
                </p>
            </div>

            <div
                class="flex flex-col items-center gap-1 rounded-[10px] border-[0.5px] border-edge-card bg-surface-field px-3 pt-3.5 pb-3 text-center shadow-field"
            >
                <p
                    v-for="(line, index) in fingerprintLines"
                    :key="index"
                    class="font-ui-mono text-[17px]/6 tracking-[0.04em] text-fg-primary"
                >
                    {{ line }}
                </p>
                <p
                    class="mt-0.5 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                >
                    {{ $t('Check it matches their key’s fingerprint') }}
                </p>
            </div>

            <dl class="flex flex-col gap-2.5">
                <div
                    v-for="row in rows"
                    :key="row.key"
                    class="flex items-center gap-2"
                >
                    <dt
                        class="min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em] text-fg-muted"
                    >
                        {{ row.label }}
                    </dt>
                    <dd
                        class="flex min-w-0 items-center gap-2 text-right text-fg-primary"
                        :class="
                            row.mono
                                ? 'font-ui-mono text-[12px]/5'
                                : 'text-[13px]/5 tracking-[-0.005em]'
                        "
                    >
                        <img
                            v-if="row.key === 'machine' && machine.iconUrl"
                            :src="machine.iconUrl"
                            alt=""
                            class="size-3.5 rounded-[3px] object-cover"
                        />
                        <BrandLogo
                            v-else-if="
                                row.key === 'machine' && machine.provider
                            "
                            :name="machine.provider"
                            :size="14"
                        />
                        <span class="truncate">{{ row.value }}</span>
                    </dd>
                </div>
            </dl>

            <div v-if="waiting && canDecide" class="flex gap-2">
                <DashButton
                    class="flex-1"
                    block
                    :label="$t('Deny')"
                    :loading="busy === 'decline'"
                    :disabled="busy !== null"
                    @click="decide('decline')"
                />
                <DashButton
                    class="flex-1"
                    block
                    variant="primary"
                    :label="$t('Approve')"
                    :loading="busy === 'approve'"
                    :disabled="busy !== null"
                    @click="decide('approve')"
                />
            </div>
            <p
                v-else-if="outcome"
                class="rounded-[8px] border-[0.5px] border-edge-card px-3 py-2.5 text-[13px]/5 tracking-[-0.005em]"
                :class="
                    access.state === 'failed'
                        ? 'text-status-down'
                        : 'text-fg-secondary'
                "
            >
                {{ outcome }}
            </p>

            <p
                v-if="error"
                class="text-center text-[12px]/4 text-status-down"
                role="alert"
            >
                {{ error }}
            </p>

            <p
                class="text-center text-[12px]/4 tracking-[-0.005em] text-fg-muted"
            >
                <template v-if="waiting && canDecide">{{
                    $t(
                        'Only approve a key you expect. It works until someone removes it.',
                    )
                }}</template>
                <template v-else-if="waiting">{{
                    $t(
                        'An owner or admin of :workspace decides. You get an email either way.',
                        { workspace: workspace.name },
                    )
                }}</template>
                <Link
                    v-else
                    :href="accessUrl"
                    class="underline underline-offset-2 hover:text-fg-primary"
                    >{{
                        $t('See who has access to :name', {
                            name: machine.name,
                        })
                    }}</Link
                >
            </p>
        </section>

        <p
            class="flex items-center gap-1.5 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
        >
            <span
                class="size-1.5 rounded-full"
                :class="canDecide ? 'bg-status-live' : 'bg-status-pending'"
            />
            {{
                canDecide
                    ? $t(
                          'You can answer this because you manage members of :workspace.',
                          { workspace: workspace.name },
                      )
                    : $t('This is your request.')
            }}
        </p>
    </main>
</template>
