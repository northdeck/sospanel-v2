import '@/lib/polyfills';
import { installI18n } from '@/lib/i18n';
import { installFieldErrors } from '@/lib/fieldErrors';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { identify, initAnalytics } from '@/lib/analytics';
import type { Component } from 'vue';
import { initializeTheme } from '@/composables/useAppearance';
import AccountLayout from '@/layouts/AccountLayout.vue';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import ViewingAsFrame from '@/layouts/ViewingAsFrame.vue';
import WorkspaceLayout from '@/layouts/WorkspaceLayout.vue';
import WorkspaceSettingsLayout from '@/layouts/workspace/WorkspaceSettingsLayout.vue';
import { initializeCelebrate } from '@/lib/celebrate';
import { errorMessage, isNetworkError } from '@/lib/errors';
import { initErrorTracking } from '@/lib/errorTracking';
import { initializeFlashToast } from '@/lib/flashToast';
import { resolvePanelPage } from '@/lib/pages';
import { toast } from '@/lib/toast';
import { initializeWorkspaceTheme } from '@/lib/workspaceTheme';

const appName = import.meta.env.VITE_APP_NAME || 'ServerOS';

function isSettingsItem(name: string, props: Record<string, unknown>): boolean {
    const extensions = props.extensions as
        | { settings?: { component: string }[] }
        | null
        | undefined;

    return (extensions?.settings ?? []).some((item) => item.component === name);
}

function pickLayout(
    name: string,
    props: Record<string, unknown>,
): Component | Component[] | null {
    switch (true) {
        case name.startsWith('errors/'):
        case name.startsWith('status/'):
        case name.startsWith('themes/'):
        case name.startsWith('survey/'):
        case name.startsWith('welcome/'):
        case name.startsWith('install/'):
            return null;
        case name.startsWith('admin/'):
            return AdminLayout;
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        case name.startsWith('workspace/settings/'):
        case name.startsWith('workspace/') && isSettingsItem(name, props):
            return [WorkspaceLayout, WorkspaceSettingsLayout];
        case name.startsWith('workspace/'):
            return WorkspaceLayout;
        case name.startsWith('workspaces/'):
            return AccountLayout;
        default:
            return AppLayout;
    }
}

void createInertiaApp({
    resolve: resolvePanelPage,
    // NORTHDECK: the name admins set on /admin/panel, not only the build's.
    title: (title, page) => {
        const name =
            typeof page?.props.name === 'string' && page.props.name !== ''
                ? page.props.name
                : appName;

        return !title
            ? name
            : page?.props.pageTitle && page.component === 'status/Show'
              ? title
              : `${title} - ${name}`;
    },
    layout: (name, page) => {
        const layout = pickLayout(name, page?.props ?? {});

        return layout === null
            ? null
            : [ViewingAsFrame, ...(Array.isArray(layout) ? layout : [layout])];
    },
    withApp: (app, { page }) => {
        initializeWorkspaceTheme(page.props);
        installI18n(
            app,
            typeof page.props.locale === 'string' ? page.props.locale : 'en',
        );
        installFieldErrors();
        initErrorTracking(app);
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

requestAnimationFrame(() => document.getElementById('page-outline')?.remove());

initializeTheme();

initializeFlashToast();

initializeCelebrate();

router.on('networkError', (event) => {
    if (isNetworkError(event.detail.error))
        toast.error(errorMessage(event.detail.error), { id: 'network' });
});

initAnalytics('panel');
router.on('navigate', (event) => {
    const user = (
        event.detail.page.props.auth as { user?: { id?: number } } | undefined
    )?.user;
    if (user?.id) identify(user.id);
});
