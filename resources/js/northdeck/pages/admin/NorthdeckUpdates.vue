<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import InputError from '@/components/InputError.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import Tile from '@/components/workspace/Tile.vue';

type Commit = {
    sha: string;
    short: string;
    message: string;
    author: string | null;
    date: string | null;
    url: string | null;
};

const props = defineProps<{
    repo: string;
    branch: string;
    current: string | null;
    check: {
        checkedAt: string;
        reached: boolean;
        error: string | null;
        latest: Commit | null;
        behind: number | null;
        commits: Commit[];
    } | null;
    available: boolean;
    updater: boolean;
    auto: boolean;
    autoHour: number | null;
    updates: {
        id: number;
        commit: string;
        from: string | null;
        status: 'requested' | 'running' | 'done' | 'failed';
        auto: boolean;
        by: string | null;
        error: string | null;
        at: string | null;
    }[];
}>();

const page = usePage<{ errors: Record<string, string> }>();

const repoUrl = computed(() => `https://github.com/${props.repo}`);
const inFlight = computed(() =>
    props.updates.some(
        (u) => u.status === 'requested' || u.status === 'running',
    ),
);

const when = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '';

function checkNow(): void {
    router.post('/admin/updates/check', {}, { preserveScroll: true });
}

function updateNow(): void {
    router.post('/admin/updates', {}, { preserveScroll: true });
}

// While an update runs, look again every 10 seconds. The panel goes away
// for a few minutes while it restarts; the reload just fails until then.
let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (inFlight.value)
            router.reload({
                only: ['updates', 'current', 'check', 'available'],
            });
    }, 10000);
});
onBeforeUnmount(() => clearInterval(timer));

const statusText: Record<string, string> = {
    requested: 'Waiting for the server',
    running: 'Updating',
    done: 'Done',
    failed: 'Didn’t work',
};
</script>

<template>
    <Head :title="$t('Updates')" />

    <div class="mx-auto flex max-w-4xl flex-col gap-6 px-6 py-8">
        <div class="flex flex-col gap-1">
            <h1 class="text-[18px]/6 font-semibold">{{ $t('Updates') }}</h1>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{ $t('This panel updates from') }}
                <a
                    :href="repoUrl"
                    target="_blank"
                    rel="noopener"
                    class="font-ui-mono underline underline-offset-4"
                    >{{ repo }}</a
                >
                ({{ branch }}).
                {{
                    $t(
                        'The server builds the new commit, backs up and restarts on it.',
                    )
                }}
            </p>
        </div>

        <Tile icon="update-fill" :title="$t('This panel')" :padded="false">
            <div class="ds-tile-rows">
                <SettingsLine :label="$t('Running')">
                    <p class="text-[13px]/5 md:pt-1.5">
                        <a
                            v-if="current"
                            :href="`${repoUrl}/commit/${current}`"
                            target="_blank"
                            rel="noopener"
                            class="font-ui-mono underline underline-offset-4"
                            >{{ current.slice(0, 7) }}</a
                        >
                        <span v-else class="text-(--ds-text-muted)">{{
                            $t(
                                'Unknown: this image was built without its commit. The next update records it.',
                            )
                        }}</span>
                    </p>
                </SettingsLine>

                <SettingsLine :label="$t('Newest on :branch', { branch })">
                    <div class="flex flex-col gap-1 text-[13px]/5 md:pt-1.5">
                        <template v-if="check?.latest">
                            <span>
                                <a
                                    :href="check.latest.url ?? repoUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-ui-mono underline underline-offset-4"
                                    >{{ check.latest.short }}</a
                                >
                                {{ check.latest.message }}
                            </span>
                            <span class="text-(--ds-text-muted)">
                                <template v-if="check.behind === 0">{{
                                    $t('You’re on it.')
                                }}</template>
                                <template v-else-if="check.behind">{{
                                    $t(
                                        ':count commits newer than this panel.',
                                        {
                                            count: String(check.behind),
                                        },
                                    )
                                }}</template>
                            </span>
                        </template>
                        <span v-else class="text-(--ds-text-muted)">{{
                            $t('Not checked yet.')
                        }}</span>
                        <span v-if="check?.error" class="text-(--ds-red)">{{
                            check.error
                        }}</span>
                        <span
                            v-if="check"
                            class="text-[12px]/4 text-(--ds-text-faint)"
                            >{{
                                $t('Checked :when. It checks every hour.', {
                                    when: when(check.checkedAt),
                                })
                            }}</span
                        >
                    </div>
                </SettingsLine>

                <SettingsLine
                    :label="$t('Auto-update')"
                    :hint="$t('Change it in Settings › Updates and privacy.')"
                >
                    <p class="text-[13px]/5 md:pt-1.5">
                        <template v-if="!auto">{{ $t('Off') }}</template>
                        <template v-else-if="autoHour === null">{{
                            $t('On, as soon as a new commit is found')
                        }}</template>
                        <template v-else>{{
                            $t('On, at :hour:00 server time', {
                                hour: String(autoHour).padStart(2, '0'),
                            })
                        }}</template>
                        ·
                        <Link
                            href="/admin/settings"
                            class="underline underline-offset-4"
                            >{{ $t('Settings') }}</Link
                        >
                    </p>
                </SettingsLine>

                <SettingsLine v-if="!updater" :label="$t('Server updater')">
                    <p class="text-[13px]/5 text-(--ds-amber) md:pt-1.5">
                        {{
                            $t(
                                'The server hasn’t checked in for a few minutes, so updates can’t start. Install Northdeck’s serveros-panel script and run serveros-panel schedule (docs/admin.md, "Updates").',
                            )
                        }}
                    </p>
                </SettingsLine>
            </div>
        </Tile>

        <div class="flex flex-wrap items-center justify-end gap-2">
            <InputError :message="page.props.errors.update" class="mr-auto" />
            <AppButton @click="checkNow">{{ $t('Check now') }}</AppButton>
            <AppButton
                variant="primary"
                :disabled="!available || !updater || inFlight"
                @click="updateNow"
            >
                {{ inFlight ? $t('Updating…') : $t('Update now') }}
            </AppButton>
        </div>

        <Tile
            v-if="check?.commits.length"
            icon="branch-fill"
            :title="$t('What’s new')"
            :padded="false"
        >
            <ul class="divide-y divide-(--ds-divider)">
                <li
                    v-for="c in check.commits"
                    :key="c.sha"
                    class="flex gap-3 px-5 py-2.5 text-[13px]/5"
                >
                    <a
                        :href="c.url ?? repoUrl"
                        target="_blank"
                        rel="noopener"
                        class="font-ui-mono text-(--ds-text-muted) underline-offset-4 hover:underline"
                        >{{ c.short }}</a
                    >
                    <span class="min-w-0 grow truncate">{{ c.message }}</span>
                    <span class="shrink-0 text-(--ds-text-faint)">{{
                        c.author
                    }}</span>
                </li>
            </ul>
        </Tile>

        <Tile
            v-if="updates.length"
            icon="activity-fill"
            :title="$t('Recent updates')"
            :padded="false"
        >
            <ul class="divide-y divide-(--ds-divider)">
                <li
                    v-for="u in updates"
                    :key="u.id"
                    class="flex flex-col gap-0.5 px-5 py-2.5 text-[13px]/5"
                >
                    <div class="flex flex-wrap items-center gap-x-3">
                        <span class="font-ui-mono"
                            >{{ u.from ?? '?' }} → {{ u.commit }}</span
                        >
                        <span
                            :class="{
                                'text-(--ds-red)': u.status === 'failed',
                                'text-(--ds-amber)':
                                    u.status === 'requested' ||
                                    u.status === 'running',
                                'text-(--ds-text-muted)': u.status === 'done',
                            }"
                            >{{ $t(statusText[u.status] ?? u.status) }}</span
                        >
                        <span class="grow" />
                        <span class="text-(--ds-text-faint)"
                            >{{ u.auto ? $t('Auto-update') : u.by }} ·
                            {{ when(u.at) }}</span
                        >
                    </div>
                    <p v-if="u.error" class="text-(--ds-text-muted)">
                        {{ u.error }}
                    </p>
                </li>
            </ul>
        </Tile>
    </div>
</template>
