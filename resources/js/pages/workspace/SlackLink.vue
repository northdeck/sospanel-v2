<script setup lang="ts">
import NorthdeckLogo from '@/northdeck/components/NorthdeckLogo.vue'; // NORTHDECK
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import ViewingAsFrame from '@/layouts/ViewingAsFrame.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import linkRoutes from '@/routes/workspace/integrations/slack/link';

defineOptions({ layout: ViewingAsFrame });

const props = defineProps<{
    workspace: { name: string; slug: string };
    integration: { id: number; label: string; team: string };
    token: string;
    state: 'ready' | 'expired' | 'done';
    slack: { name: string | null } | null;
    viewer: { name: string; email: string };
    replaces: string | null;
}>();

const busy = ref(false);
const slackName = computed(() =>
    props.slack?.name ? `@${props.slack.name}` : null,
);

function link(): void {
    if (busy.value) return;
    router.post(
        onWorkspace(
            linkRoutes.store({
                workspace: props.workspace.slug,
                integration: props.integration.id,
                token: props.token,
            }),
        ).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}
</script>

<template>
    <Head :title="$t('Link your Slack account')" />

    <main
        class="flex min-h-svh flex-col items-center justify-center gap-7 bg-surface-main px-4 py-10 font-ui text-fg-primary"
    >
        <div class="flex items-center gap-2.5">
            <!-- NORTHDECK: the Northdeck wordmark -->
            <NorthdeckLogo :height="20" />
        </div>

        <section
            class="flex w-full max-w-[420px] flex-col gap-[18px] rounded-[14px] border-[0.5px] border-edge-control bg-surface-canvas px-6 pt-6 pb-[22px] shadow-[0_12px_32px_-12px_rgb(0_0_0/0.4),0_1px_2px_rgb(0_0_0/0.5)]"
            aria-labelledby="slack-link-title"
        >
            <div class="flex items-start gap-3">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-[9px] border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] dark:border-[#424242] dark:bg-[#262626]"
                    aria-hidden="true"
                >
                    <BrandLogo name="slack" :size="20" />
                </span>
                <div class="flex min-w-0 flex-col gap-1">
                    <h1
                        id="slack-link-title"
                        class="text-[18px]/[26px] font-medium tracking-[-0.015em] text-fg-primary"
                    >
                        {{
                            state === 'done'
                                ? $t('Your Slack account is linked')
                                : state === 'expired'
                                  ? $t('This link has run out')
                                  : $t('Link your Slack account')
                        }}
                    </h1>
                    <p
                        class="text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                    >
                        {{ $t('Signed in as :email', { email: viewer.email }) }}
                    </p>
                </div>
            </div>

            <dl v-if="state !== 'expired'" class="flex flex-col gap-2.5">
                <div class="flex items-center gap-2">
                    <dt
                        class="min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em] text-fg-muted"
                    >
                        {{ $t('Slack account') }}
                    </dt>
                    <dd class="truncate font-ui-mono text-[12px]/5">
                        {{ slackName ?? '–' }}
                    </dd>
                </div>
                <div class="flex items-center gap-2">
                    <dt
                        class="min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em] text-fg-muted"
                    >
                        {{ $t('In Slack') }}
                    </dt>
                    <dd class="truncate text-[13px]/5">
                        {{ integration.team }}
                    </dd>
                </div>
                <div class="flex items-center gap-2">
                    <dt
                        class="min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em] text-fg-muted"
                    >
                        {{ $t('Acts as') }}
                    </dt>
                    <dd class="truncate text-[13px]/5">
                        {{
                            $t(':name in :workspace', {
                                name: viewer.name,
                                workspace: workspace.name,
                            })
                        }}
                    </dd>
                </div>
            </dl>

            <p
                class="rounded-[8px] border-[0.5px] border-edge-card px-3 py-2.5 text-[13px]/5 tracking-[-0.005em] text-fg-secondary"
            >
                <template v-if="state === 'done'">{{
                    $t(
                        'Go back to Slack and press the button or type the command again. It runs as you now.',
                    )
                }}</template>
                <template v-else-if="state === 'expired'">{{
                    $t(
                        'Links work once, for 30 minutes. Press the button in Slack again, or type /serveros status, for a new one.',
                    )
                }}</template>
                <template v-else>
                    {{
                        $t(
                            'What this Slack account presses or types in Slack will run as you, with your role. Only link your own Slack account.',
                        )
                    }}
                    <template v-if="replaces">{{
                        ' ' +
                        $t(
                            'It replaces @:name, the Slack account you linked before.',
                            {
                                name: replaces,
                            },
                        )
                    }}</template>
                </template>
            </p>

            <DashButton
                v-if="state === 'ready'"
                block
                variant="primary"
                :label="$t('Link my Slack account')"
                :loading="busy"
                @click="link"
            />
        </section>
    </main>
</template>
