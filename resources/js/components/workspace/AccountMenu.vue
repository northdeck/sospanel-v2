<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuPortal,
    DropdownMenuRoot,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from 'reka-ui';
import serverosMark from '@/components/dash/assets/serveros-mark.svg?raw';
import DashAvatar from '@/components/dash/DashAvatar.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import logoutFill from '@/components/workspace/account/logout-fill.svg?raw';
import moonFill from '@/components/workspace/account/moon-fill.svg?raw';
import proFill from '@/components/workspace/account/pro-fill.svg?raw';
import { useAssistant } from '@/composables/useAssistant';
import { useWhatsNew } from '@/composables/useWhatsNew';
import { useAppearance } from '@/composables/useAppearance';
import type { Appearance } from '@/composables/useAppearance';
import { docsUrl } from '@/lib/docs';
import { flagUrl } from '@/lib/flags';
import { changeLocale } from '@/lib/locale';
import { onWorkspace } from '@/lib/workspaceRoute';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import { profile as workspaceProfile } from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

type Props = {
    display?: 'full' | 'avatar' | 'sidebar';
    side?: 'top' | 'right' | 'bottom';
    align?: 'start' | 'end';
};

const {
    display = 'full',
    side = 'top',
    align = 'start',
} = defineProps<Props>();

const page = usePage<{
    appUrl: string;
    locale: string;
    locales: Record<string, string>;
    panelVersion?: string | null;
    workspace?: Workspace;
    extensions?: { nav: { key: string; label: string; path: string }[] } | null;
    staff?: { admin: boolean; sales: boolean }; // NORTHDECK: the Admin link
}>();
const user = computed(() => page.props.auth.user);

const accountSettings = computed<{ href: string; external: boolean }>(() => {
    if (page.props.workspace) {
        return {
            href: onWorkspace(workspaceProfile(page.props.workspace.slug)).url,
            external: false,
        };
    }

    const all = page.props.workspaces ?? [];
    const home = all.find((w) => w.pinnedAt !== null) ?? all[0];

    return home
        ? {
              href: `${home.url.replace(/\/$/, '')}/settings/profile`,
              external: true,
          }
        : { href: edit().url, external: false };
});
const support = computed(
    () =>
        page.props.extensions?.nav.find((item) => item.key === 'support') ??
        null,
);
const docs = computed(() => docsUrl(page.props.docsUrl));
const feedback = computed(() =>
    page.props.feedbackUrl
        ? `${page.props.feedbackUrl.replace(/\/$/, '')}/`
        : null,
);

const { appearance, updateAppearance } = useAppearance();
const whatsNew = useWhatsNew();
const assistant = useAssistant();

const themes = computed(
    () =>
        [
            { value: 'light', label: trans('Light') },
            { value: 'dark', label: trans('Dark') },
            { value: 'system', label: trans('System') },
        ] as const satisfies { value: Appearance; label: string }[],
);
const themeLabel = computed(
    () =>
        themes.value.find((theme) => theme.value === appearance.value)?.label ??
        trans('System'),
);

const version = computed(() => {
    const value = page.props.panelVersion;

    return value ? (/^\d/.test(value) ? `v${value}` : value) : null;
});

const ROLES: Record<string, string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    viewer: 'Viewer',
};

const role = computed(() => {
    const key = page.props.workspace ? page.props.workspaceAccess?.role : null;

    return key ? trans(ROLES[key] ?? key) : null;
});

const upgrade = computed(() => {
    const workspace = page.props.workspace;
    const link = page.props.upgrade;
    const canManage =
        page.props.workspaceAccess?.permissions.includes('manage-billing') ??
        false;

    if (!workspace || !link || !canManage || workspace.billing.selfHosted) {
        return null;
    }

    const { onTrial, plan, planLabel } = workspace.billing;

    if (onTrial && planLabel) {
        return {
            label: trans('Upgrade to :plan', { plan: planLabel }),
            href: link.href,
            highlight: true,
        };
    }

    if (plan === null) {
        return {
            label: trans('Pick a plan'),
            href: link.href,
            highlight: true,
        };
    }

    return { label: trans('Billing'), href: link.href, highlight: false };
});

const content =
    'z-50 flex w-[260px] flex-col overflow-clip rounded-[12px] border-[0.5px] border-edge-strong bg-surface-hover font-ui shadow-[0_8px_16px_-6px_rgb(0_0_0/0.6),0_1px_2px_rgb(0_0_0/0.5)] outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[side=bottom]:slide-in-from-top-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2';
const subContent =
    'z-50 flex min-w-44 flex-col rounded-[10px] border-[0.5px] border-edge-strong bg-surface-hover p-1.5 font-ui shadow-[0_8px_16px_-6px_rgb(0_0_0/0.6),0_1px_2px_rgb(0_0_0/0.5)] outline-none data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95';
const row =
    'group/row flex h-[34px] w-full cursor-pointer items-center gap-3 rounded-[7px] pr-2 pl-2.5 text-left text-[13px]/5 tracking-[-0.005em] text-fg-secondary outline-none select-none data-highlighted:bg-surface-raised data-highlighted:text-fg-primary data-[state=open]:bg-surface-raised data-[state=open]:text-fg-primary';
const subRow =
    'flex h-8 w-full cursor-pointer items-center gap-2.5 rounded-[7px] px-2 text-left text-[13px]/5 tracking-[-0.005em] text-fg-secondary outline-none select-none data-highlighted:bg-surface-raised data-highlighted:text-fg-primary';
const icon =
    'flex size-4 shrink-0 text-fg-tertiary transition-colors group-data-highlighted/row:text-fg-secondary group-data-[state=open]/row:text-fg-secondary [&>svg]:size-full';
const value = 'shrink-0 text-fg-muted';
const shortcut =
    'ml-auto font-ui-mono text-[11px]/3.5 text-fg-faint pointer-coarse:hidden';
</script>

<template>
    <DropdownMenuRoot>
        <DropdownMenuTrigger v-if="display === 'sidebar'" as-child>
            <button
                type="button"
                class="group flex w-full min-w-0 cursor-pointer items-center gap-2 px-3.5 py-3 text-left font-ui transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-surface-raised"
                :aria-label="
                    $t('Account menu for :name', {
                        name: String(user.name),
                    })
                "
                data-test="workspace-user-menu"
            >
                <span class="relative shrink-0">
                    <DashAvatar
                        :src="user.avatar"
                        :name="user.name"
                        :size="28"
                    />
                    <span
                        v-if="whatsNew.unread.value"
                        class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-(--ds-blue) ring-2 ring-surface-sidebar"
                    >
                        <span class="sr-only">{{
                            $t('Something new in What’s new')
                        }}</span>
                    </span>
                </span>
                <span
                    class="flex min-w-0 flex-1 flex-col text-[13px]/4 font-medium"
                >
                    <span class="truncate tracking-[-0.01em] text-fg-primary">{{
                        user.name
                    }}</span>
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{ user.email }}</span
                    >
                </span>
                <FilledIcon
                    name="chevrons-up-down"
                    class="text-fg-muted transition-colors group-hover:text-fg-tertiary group-data-[state=open]:text-fg-tertiary"
                />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuTrigger v-else as-child>
            <button
                type="button"
                class="flex cursor-pointer items-center gap-2 rounded-[10px] text-left hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) data-[state=open]:bg-(--ds-hover)"
                :class="
                    display === 'full'
                        ? 'min-h-14 w-full px-1.5 py-2'
                        : 'size-10 shrink-0 justify-center'
                "
                :aria-label="
                    display === 'avatar'
                        ? $t('Account menu for :name', {
                              name: String(user.name),
                          })
                        : undefined
                "
                data-test="workspace-user-menu"
            >
                <span class="relative shrink-0">
                    <DashAvatar
                        :src="user.avatar"
                        :name="user.name"
                        :size="32"
                    />
                    <span
                        v-if="whatsNew.unread.value"
                        class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full bg-(--ds-blue) ring-2 ring-(--ds-frame)"
                    >
                        <span class="sr-only">{{
                            $t('Something new in What’s new')
                        }}</span>
                    </span>
                </span>
                <span
                    v-if="display === 'full'"
                    class="flex min-w-0 flex-col gap-0.5 text-xs/4"
                >
                    <span class="truncate font-medium text-(--ds-text)">
                        {{ user.name }}
                    </span>
                    <span class="truncate text-(--ds-text-secondary)">
                        {{ user.email }}
                    </span>
                </span>
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuPortal>
            <DropdownMenuContent
                :class="content"
                :side="side"
                :align="align"
                :side-offset="6"
                :align-offset="display === 'sidebar' ? 8 : 0"
                :collision-padding="8"
            >
                <div class="flex items-center gap-2 px-3.5 py-3">
                    <span
                        class="flex h-3.5 w-[12.39px] shrink-0 text-fg-primary [&>svg]:size-full"
                        aria-hidden="true"
                        v-html="serverosMark"
                    />
                    <span
                        class="text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                        >{{ page.props.name }}</span
                    >
                    <span class="flex-1" />
                    <span
                        v-if="version"
                        class="max-w-28 truncate font-ui text-[12px]/4 tracking-[-0.005em] text-fg-faint"
                        >{{ version }}</span
                    >
                </div>

                <div
                    class="-mx-[0.5px] flex flex-col rounded-t-[12px] border-[0.5px] border-edge-strong bg-surface-main p-1.5"
                >
                    <DropdownMenuItem as-child>
                        <a
                            v-if="accountSettings.external"
                            :href="accountSettings.href"
                            :class="row"
                        >
                            <FilledIcon name="user-fill" :class="icon" />
                            <span class="min-w-0 flex-1 truncate">{{
                                $t('Account settings')
                            }}</span>
                        </a>
                        <Link
                            v-else
                            :href="accountSettings.href"
                            prefetch
                            :class="row"
                        >
                            <FilledIcon name="user-fill" :class="icon" />
                            <span class="min-w-0 flex-1 truncate">{{
                                $t('Account settings')
                            }}</span>
                        </Link>
                    </DropdownMenuItem>

                    <!-- NORTHDECK: instance admins get a way into /admin -->
                    <DropdownMenuItem v-if="page.props.staff?.admin" as-child>
                        <a :href="`${page.props.appUrl}/admin`" :class="row">
                            <FilledIcon name="key-fill" :class="icon" />
                            <span class="min-w-0 flex-1 truncate">{{
                                $t('Admin')
                            }}</span>
                        </a>
                    </DropdownMenuItem>

                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger :class="row">
                            <span
                                :class="icon"
                                aria-hidden="true"
                                v-html="moonFill"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                $t('Theme')
                            }}</span>
                            <span :class="value">{{ themeLabel }}</span>
                            <FilledIcon
                                name="chevron-right"
                                :size="14"
                                class="text-fg-faint"
                            />
                        </DropdownMenuSubTrigger>
                        <DropdownMenuPortal>
                            <DropdownMenuSubContent
                                :class="subContent"
                                :side-offset="6"
                            >
                                <DropdownMenuItem
                                    v-for="theme in themes"
                                    :key="theme.value"
                                    :class="subRow"
                                    @select="updateAppearance(theme.value)"
                                >
                                    <span class="flex-1">{{
                                        theme.label
                                    }}</span>
                                    <FilledIcon
                                        v-if="appearance === theme.value"
                                        name="check"
                                        :size="14"
                                        class="text-fg-primary"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuSubContent>
                        </DropdownMenuPortal>
                    </DropdownMenuSub>

                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger :class="row">
                            <FilledIcon name="globe-fill" :class="icon" />
                            <span class="min-w-0 flex-1 truncate">{{
                                $t('Language')
                            }}</span>
                            <span :class="value">{{
                                page.props.locales[page.props.locale]
                            }}</span>
                            <FilledIcon
                                name="chevron-right"
                                :size="14"
                                class="text-fg-faint"
                            />
                        </DropdownMenuSubTrigger>
                        <DropdownMenuPortal>
                            <DropdownMenuSubContent
                                :class="subContent"
                                :side-offset="6"
                            >
                                <DropdownMenuItem
                                    v-for="(name, code) in page.props.locales"
                                    :key="code"
                                    :class="subRow"
                                    @select="
                                        page.props.locale !== code &&
                                        changeLocale(code)
                                    "
                                >
                                    <img
                                        :src="flagUrl(code)"
                                        alt=""
                                        class="h-2.5 w-3.75 shrink-0 rounded-[2px] object-cover"
                                    />
                                    <span class="flex-1">{{ name }}</span>
                                    <FilledIcon
                                        v-if="page.props.locale === code"
                                        name="check"
                                        :size="14"
                                        class="text-fg-primary"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuSubContent>
                        </DropdownMenuPortal>
                    </DropdownMenuSub>

                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger :class="row">
                            <FilledIcon name="help-fill" :class="icon" />
                            <span class="min-w-0 flex-1 truncate">{{
                                $t('Help')
                            }}</span>
                            <span
                                v-if="whatsNew.unread.value"
                                class="size-1.5 shrink-0 rounded-full bg-(--ds-blue)"
                                :aria-label="$t('New updates')"
                            />
                            <FilledIcon
                                name="chevron-right"
                                :size="14"
                                class="text-fg-faint"
                            />
                        </DropdownMenuSubTrigger>
                        <DropdownMenuPortal>
                            <DropdownMenuSubContent
                                :class="subContent"
                                :side-offset="6"
                            >
                                <DropdownMenuItem
                                    v-if="page.props.workspace"
                                    :class="subRow"
                                    @select="assistant.toggle()"
                                >
                                    <span class="flex-1">{{
                                        $t('Ask AI')
                                    }}</span>
                                    <kbd :class="shortcut">⌘J</kbd>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    :class="subRow"
                                    @select="whatsNew.show()"
                                >
                                    <span class="flex-1">{{
                                        $t('What’s new')
                                    }}</span>
                                    <span
                                        v-if="whatsNew.unread.value"
                                        class="rounded-full bg-(--ds-blue) px-1.5 text-[10.5px]/4 font-semibold text-white tabular-nums"
                                        >{{
                                            whatsNew.unread.value > 9
                                                ? '9+'
                                                : whatsNew.unread.value
                                        }}</span
                                    >
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="page.props.workspace && support"
                                    as-child
                                >
                                    <Link :href="support.path" :class="subRow">
                                        <span class="flex-1">{{
                                            support.label
                                        }}</span>
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem as-child>
                                    <a
                                        :href="docs"
                                        target="_blank"
                                        rel="noopener"
                                        :class="subRow"
                                    >
                                        <span class="flex-1">{{
                                            $t('Docs')
                                        }}</span>
                                        <FilledIcon
                                            name="open-fill"
                                            :size="14"
                                            class="text-fg-faint"
                                        />
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem v-if="feedback" as-child>
                                    <a
                                        :href="feedback"
                                        target="_blank"
                                        rel="noopener"
                                        :class="subRow"
                                    >
                                        <span class="flex-1">{{
                                            $t('Feedback')
                                        }}</span>
                                        <FilledIcon
                                            name="open-fill"
                                            :size="14"
                                            class="text-fg-faint"
                                        />
                                    </a>
                                </DropdownMenuItem>
                            </DropdownMenuSubContent>
                        </DropdownMenuPortal>
                    </DropdownMenuSub>

                    <DropdownMenuItem v-if="upgrade" as-child>
                        <Link :href="upgrade.href" :class="row">
                            <span
                                v-if="upgrade.highlight"
                                class="flex size-4 shrink-0 text-[#99a8ff] [&>svg]:size-full"
                                aria-hidden="true"
                                v-html="proFill"
                            />
                            <FilledIcon
                                v-else
                                name="setup-fill"
                                :class="icon"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                upgrade.label
                            }}</span>
                        </Link>
                    </DropdownMenuItem>

                    <div class="px-2.5 py-[5px]" role="separator">
                        <div class="h-[0.5px] bg-edge-field" />
                    </div>

                    <DropdownMenuItem as-child>
                        <Link
                            :href="logout()"
                            as="button"
                            :class="row"
                            data-test="logout-button"
                            @click="router.flushAll()"
                        >
                            <span
                                :class="icon"
                                aria-hidden="true"
                                v-html="logoutFill"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                $t('Log out')
                            }}</span>
                        </Link>
                    </DropdownMenuItem>
                </div>

                <div
                    class="flex min-w-0 flex-col gap-0.5 px-3.5 pt-3 pb-[13px]"
                >
                    <p
                        class="truncate text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                    >
                        {{ user.name }}
                    </p>
                    <p
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                    >
                        <template v-if="role"
                            ><span class="text-[#87a3ff]">{{ role }}</span
                            ><span class="text-fg-faint">{{
                                ' · '
                            }}</span></template
                        >{{ user.email }}
                    </p>
                </div>
            </DropdownMenuContent>
        </DropdownMenuPortal>
    </DropdownMenuRoot>
</template>
