<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppButton from '@/components/workspace/AppButton.vue';

type AdminUser = {
    id: number;
    name: string;
    email: string;
    verified: boolean;
    twoFactor: boolean;
    admin: boolean;
    suspended: boolean;
    workspaces: number;
    createdAt: string | null;
};

type PageLink = { url: string | null; label: string; active: boolean };

const props = defineProps<{
    users: {
        data: AdminUser[];
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
        from: number | null;
        to: number | null;
        links: PageLink[];
    };
    search: string;
    me: number | null;
    invites: {
        id: number;
        email: string;
        workspace: string | null;
        invitedBy: string | null;
        expiresAt: string;
        status: 'pending' | 'expired' | 'accepted';
    }[];
    workspaces: { id: number; name: string }[];
    roles: { value: string; label: string }[];
    signUps: { mode: string; domains: string[] };
}>();

const inviting = ref(false);
const invite = useForm<{
    email: string;
    workspace_id: number | null;
    role: string;
}>({ email: '', workspace_id: null, role: 'member' });

function startInvite(): void {
    invite.reset();
    invite.clearErrors();
    inviting.value = true;
}

function sendInvite(): void {
    invite.post('/admin/invites', {
        preserveScroll: true,
        onSuccess: () => {
            inviting.value = false;
            invite.reset();
        },
    });
}

function resend(id: number): void {
    router.post(`/admin/invites/${id}/resend`, {}, { preserveScroll: true });
}

function revoke(id: number, email: string): void {
    if (!window.confirm(trans('Cancel the invite to :email?', { email }))) {
        return;
    }
    router.delete(`/admin/invites/${id}`, { preserveScroll: true });
}

const modeText: Record<string, string> = {
    open: 'Anyone can sign up.',
    domains: 'Only emails on the allowed domains can sign up.',
    invite: 'Only people with an invite can sign up.',
    off: 'Sign-up is off: only people you invite get in.',
};

const query = ref(props.search);
let timer: ReturnType<typeof setTimeout> | undefined;
watch(query, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            '/admin/users',
            value.trim() === '' ? {} : { q: value.trim() },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 250);
});

const editing = ref<AdminUser | null>(null);
const open = ref(false);
const form = useForm({ name: '', email: '', verified: false, admin: false });

function edit(user: AdminUser): void {
    editing.value = user;
    form.clearErrors();
    form.name = user.name;
    form.email = user.email;
    form.verified = user.verified;
    form.admin = user.admin;
    open.value = true;
}

function save(): void {
    if (!editing.value) return;
    form.put(`/admin/users/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

const emailChanging = (): boolean =>
    editing.value !== null &&
    form.email.trim().toLowerCase() !== editing.value.email.toLowerCase();

const date = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleDateString() : '';
</script>

<template>
    <Head :title="$t('Users')" />

    <div class="flex flex-col">
        <div class="flex flex-col gap-1 px-6 pt-8 pb-5">
            <h1 class="text-[18px]/6 font-semibold">{{ $t('Users') }}</h1>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        'Everyone with an account on this panel. Click someone to change their name, email or admin access.',
                    )
                }}
            </p>
        </div>

        <div class="flex items-center gap-3 px-6 pb-3">
            <SearchWell
                v-model="query"
                :placeholder="$t('Search names and emails')"
                :width="280"
            />
            <span class="text-[13px]/5 text-(--ds-text-muted)">
                {{ $t(':count people', { count: String(users.total) }) }}
            </span>
            <span class="grow" />
            <AppButton variant="primary" size="sm" @click="startInvite">{{
                $t('Invite someone')
            }}</AppButton>
        </div>
        <p class="px-6 pb-3 text-[13px]/5 text-(--ds-text-muted)">
            {{ $t(modeText[signUps.mode] ?? '') }}
            <template
                v-if="signUps.mode === 'domains' && signUps.domains.length"
            >
                ({{ signUps.domains.map((d) => '@' + d).join(', ') }})
            </template>
            {{ $t('Invites work whatever this is set to.') }}
            <Link href="/admin/settings" class="underline underline-offset-4">{{
                $t('Change it')
            }}</Link>
        </p>

        <div v-if="invites.length" class="px-6 pb-5">
            <h2 class="pb-2 text-[13px]/5 font-medium">
                {{ $t('Invites') }}
            </h2>
            <ul
                class="divide-y divide-(--ds-divider) rounded-[10px] border border-(--ds-border)"
            >
                <li
                    v-for="inv in invites"
                    :key="inv.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-[13px]/5"
                >
                    <span class="font-medium">{{ inv.email }}</span>
                    <span v-if="inv.workspace" class="text-(--ds-text-muted)">{{
                        $t('to :workspace', { workspace: inv.workspace })
                    }}</span>
                    <span
                        class="rounded-[4px] px-1.5 text-[11px]/5"
                        :class="
                            inv.status === 'expired'
                                ? 'bg-(--ds-amber)/10 text-(--ds-amber)'
                                : 'bg-(--ds-hover) text-(--ds-text-muted)'
                        "
                        >{{
                            inv.status === 'expired'
                                ? $t('Expired')
                                : $t('Until :date', {
                                      date: date(inv.expiresAt),
                                  })
                        }}</span
                    >
                    <span class="grow" />
                    <button
                        type="button"
                        class="cursor-pointer text-(--ds-text-muted) underline-offset-4 hover:underline"
                        @click="resend(inv.id)"
                    >
                        {{ $t('Send again') }}
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer text-(--ds-red) underline-offset-4 hover:underline"
                        @click="revoke(inv.id, inv.email)"
                    >
                        {{ $t('Cancel') }}
                    </button>
                </li>
            </ul>
        </div>

        <DashTable class="border-t-[0.5px] border-edge-tabs">
            <template #head>
                <DashTh icon="user-fill" :width="220" first>{{
                    $t('Name')
                }}</DashTh>
                <DashTh icon="mail-fill" :width="260">{{ $t('Email') }}</DashTh>
                <DashTh icon="layers-fill" :width="120">{{
                    $t('Workspaces')
                }}</DashTh>
                <DashTh icon="key-fill" :width="220">{{ $t('Access') }}</DashTh>
                <DashTh icon="activity-fill" fill>{{ $t('Joined') }}</DashTh>
            </template>

            <DashTr
                v-for="user in users.data"
                :key="user.id"
                clickable
                @click="edit(user)"
            >
                <DashTd first>
                    <button
                        type="button"
                        class="cursor-pointer truncate px-3 text-left text-[13px]/5 font-medium"
                        @click.stop="edit(user)"
                    >
                        {{ user.name }}
                        <span
                            v-if="user.id === me"
                            class="font-normal text-(--ds-text-muted)"
                            >{{ $t('(you)') }}</span
                        >
                    </button>
                </DashTd>
                <DashTd>
                    <span class="truncate px-3 text-[13px]/5">{{
                        user.email
                    }}</span>
                </DashTd>
                <DashTd>
                    <span
                        class="px-3 text-[13px]/5 text-(--ds-text-muted) tabular-nums"
                        >{{ user.workspaces }}</span
                    >
                </DashTd>
                <DashTd>
                    <span class="flex flex-wrap gap-1 px-3">
                        <span
                            v-if="user.admin"
                            class="rounded-[4px] bg-(--ds-hover) px-1.5 text-[11px]/5 font-medium"
                            >{{ $t('Admin') }}</span
                        >
                        <span
                            v-if="user.twoFactor"
                            class="rounded-[4px] bg-(--ds-hover) px-1.5 text-[11px]/5 text-(--ds-text-muted)"
                            >{{ $t('Two-factor') }}</span
                        >
                        <span
                            v-if="!user.verified"
                            class="rounded-[4px] bg-(--ds-amber)/10 px-1.5 text-[11px]/5 text-(--ds-amber)"
                            >{{ $t('Unverified') }}</span
                        >
                        <span
                            v-if="user.suspended"
                            class="rounded-[4px] bg-(--ds-red)/10 px-1.5 text-[11px]/5 text-(--ds-red)"
                            >{{ $t('Suspended') }}</span
                        >
                    </span>
                </DashTd>
                <DashTd>
                    <span class="px-3 text-[13px]/5 text-(--ds-text-muted)">{{
                        date(user.createdAt)
                    }}</span>
                </DashTd>
            </DashTr>

            <tr v-if="users.data.length === 0">
                <td
                    colspan="5"
                    class="px-6 py-10 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{ $t('Nobody matches that search.') }}
                </td>
            </tr>
        </DashTable>

        <div
            v-if="users.prev_page_url || users.next_page_url"
            class="flex items-center justify-between px-6 py-4 text-[13px]/5 text-(--ds-text-muted)"
        >
            <span>{{
                $t(':from to :to of :total', {
                    from: String(users.from ?? 0),
                    to: String(users.to ?? 0),
                    total: String(users.total),
                })
            }}</span>
            <span class="flex gap-2">
                <Link
                    v-if="users.prev_page_url"
                    :href="users.prev_page_url"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                    >{{ $t('Previous') }}</Link
                >
                <Link
                    v-if="users.next_page_url"
                    :href="users.next_page_url"
                    preserve-scroll
                    class="underline-offset-4 hover:underline"
                    >{{ $t('Next') }}</Link
                >
            </span>
        </div>
    </div>

    <Dialog v-model:open="inviting">
        <DialogContent class="sm:max-w-md">
            <form class="flex flex-col gap-5" @submit.prevent="sendInvite">
                <DialogHeader>
                    <DialogTitle>{{ $t('Invite someone') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'They get an email to make an account. The invite works on any domain, even with sign-up off.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="invite-email">{{ $t('Email') }}</Label>
                    <Input
                        id="invite-email"
                        v-model="invite.email"
                        type="email"
                        required
                        autocomplete="off"
                        :placeholder="$t('email@example.com')"
                    />
                    <InputError :message="invite.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="invite-workspace">{{
                        $t('Add them to a workspace')
                    }}</Label>
                    <select
                        id="invite-workspace"
                        v-model="invite.workspace_id"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    >
                        <option :value="null">
                            {{ $t('No, they make their own') }}
                        </option>
                        <option
                            v-for="w in workspaces"
                            :key="w.id"
                            :value="w.id"
                        >
                            {{ w.name }}
                        </option>
                    </select>
                    <InputError :message="invite.errors.workspace_id" />
                </div>

                <div v-if="invite.workspace_id !== null" class="grid gap-2">
                    <Label for="invite-role">{{ $t('Role') }}</Label>
                    <select
                        id="invite-role"
                        v-model="invite.role"
                        class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                    >
                        <option
                            v-for="r in roles"
                            :key="r.value"
                            :value="r.value"
                        >
                            {{ $t(r.label) }}
                        </option>
                    </select>
                </div>

                <DialogFooter>
                    <AppButton @click="inviting = false">{{
                        $t('Cancel')
                    }}</AppButton>
                    <AppButton
                        type="submit"
                        variant="primary"
                        :disabled="invite.processing"
                        >{{ $t('Send invite') }}</AppButton
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <form class="flex flex-col gap-5" @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Edit :name', { name: editing?.name ?? '' })
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'They get an email at their old address when you change it.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="admin-user-name">{{ $t('Name') }}</Label>
                    <Input
                        id="admin-user-name"
                        v-model="form.name"
                        required
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="admin-user-email">{{ $t('Email') }}</Label>
                    <Input
                        id="admin-user-email"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.email" />
                    <label
                        v-if="emailChanging()"
                        class="flex items-center gap-2 text-[13px]/5"
                    >
                        <DashCheckbox
                            v-model="form.verified"
                            :label="$t('Mark the new address as verified')"
                        />
                        <span>{{
                            $t(
                                'Mark it as verified. Otherwise they confirm it from an email.',
                            )
                        }}</span>
                    </label>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-[13px]/5 font-medium">{{
                            $t('Admin')
                        }}</span>
                        <span class="text-[13px]/5 text-(--ds-text-muted)">{{
                            $t(
                                'Opens these admin pages. They also need a verified email and two-factor on.',
                            )
                        }}</span>
                    </div>
                    <DashSwitch
                        v-model="form.admin"
                        :label="$t('Admin')"
                        :disabled="editing?.id === me"
                    />
                </div>
                <InputError :message="form.errors.admin" />

                <DialogFooter>
                    <AppButton @click="open = false">{{
                        $t('Cancel')
                    }}</AppButton>
                    <AppButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                        >{{ $t('Save') }}</AppButton
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
