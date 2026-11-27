<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onUnmounted, ref } from 'vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import Tile from '@/components/workspace/Tile.vue';
import { appButtonClasses } from '@/lib/appButton';
import VerifiedDomains from '@/components/workspace/VerifiedDomains.vue';
import type { EmailDomains } from '@/components/workspace/VerifiedDomains.vue';
import ConnectionForm from '../../../components/sso/ConnectionForm.vue';
import CopyField from '../../../components/sso/CopyField.vue';
import type {
    SsoConnection,
    SsoPreset,
    SsoRole,
} from '../../../components/sso/types';

const props = defineProps<{
    connections: SsoConnection[];
    instance: SsoConnection | null;
    presets: SsoPreset[];
    roles: SsoRole[];
    emailDomains: EmailDomains;
    requireSso: boolean;
    requireGoogle: boolean;
    isOwner: boolean;
    canBreakGlass: boolean;
    signedInWithSso: boolean;
    base: string;
    callbackHost: string;
    directUrl: string;
    oidcCallbackUrl: string;
    scim: {
        baseUrl: string;
        hasToken: boolean;
        lastUsedAt: string | null;
    } | null;
}>();

const adding = ref(props.connections.length === 0);
const editing = ref<string | null>(null);
const showClaims = ref<string | null>(null);

const connectionUrl = (c: SsoConnection): string =>
    `${props.base}/connections/${c.id}`;

const requireOn = ref(props.requireSso);
const requireError = ref<string | null>(null);
function setRequire(on: boolean): void {
    requireError.value = null;
    router.put(
        `${props.base}/require`,
        { require: on },
        {
            preserveScroll: true,
            onError: (errors) => {
                requireOn.value = props.requireSso;
                requireError.value = errors.require ?? null;
            },
        },
    );
}

function remove(c: SsoConnection): void {
    if (
        !window.confirm(
            trans(
                'Remove :name? People who sign in with it will need another way in.',
                { name: c.name },
            ),
        )
    )
        return;
    router.delete(connectionUrl(c), { preserveScroll: true });
}

const mapping = useForm({ group: '', role: 'member' });
function addMapping(c: SsoConnection): void {
    mapping.post(`${connectionUrl(c)}/groups`, {
        preserveScroll: true,
        onSuccess: () => mapping.reset('group'),
    });
}
function removeMapping(c: SsoConnection, id: number): void {
    router.delete(`${connectionUrl(c)}/groups/${id}`, { preserveScroll: true });
}

const scimToken = ref<string | null>(null);
const stopFlash = router.on('flash', (event) => {
    const token = (event as CustomEvent).detail?.flash?.scimToken;
    if (typeof token === 'string') scimToken.value = token;
});
onUnmounted(stopFlash);

function newScimToken(): void {
    if (
        props.scim?.hasToken &&
        !window.confirm(
            trans('Make a new SCIM token? The old one stops working now.'),
        )
    )
        return;
    router.post(`${props.base}/scim-token`, {}, { preserveScroll: true });
}

function revokeScimToken(): void {
    if (
        !window.confirm(
            trans(
                'Revoke the SCIM token? Provisioning stops until you make a new one.',
            ),
        )
    )
        return;
    scimToken.value = null;
    router.delete(`${props.base}/scim-token`, { preserveScroll: true });
}

const roleLabel = (value: string): string =>
    props.roles.find((r) => r.value === value)?.label ?? value;

const planText: Record<string, string> = {
    sign_in: 'Would sign in the account already linked.',
    link: 'Would link to the existing account with this email.',
    create: 'Would add a new account.',
    refuse: 'Would be refused.',
};

const muted = 'text-[13px]/5 text-(--ds-text-muted)';
const pill = 'rounded-full px-2 py-px text-[11.5px]/4 font-medium';
const select =
    'h-9 rounded-md border border-(--ds-border) bg-(--ds-field) px-2.5 text-[13px] focus-visible:outline-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Single sign-on')" />

    <div class="flex flex-col gap-6">
        <Tile
            icon="lock-fill"
            :title="$t('Single sign-on')"
            :description="
                $t(
                    'Sign in with your identity provider over OpenID Connect or SAML 2.0. People are sent to it by their email domain.',
                )
            "
            :padded="false"
        >
            <div class="ds-tile-rows">
                <SettingsLine
                    :label="$t('Require SSO')"
                    :hint="
                        $t(
                            'Members can only get in through SSO. The owner and instance admins with two-factor on can still use a password, so nobody gets locked out.',
                        )
                    "
                >
                    <div class="flex items-center gap-3 md:pt-1.5">
                        <DashSwitch
                            v-model="requireOn"
                            :label="$t('Require SSO')"
                            :disabled="requireGoogle"
                            @update:model-value="setRequire"
                        />
                        <span v-if="requireGoogle" :class="muted">
                            {{ $t('Turn off Require Google sign-in first.') }}
                        </span>
                    </div>
                    <InputError :message="requireError ?? undefined" />
                </SettingsLine>
                <SettingsLine
                    :label="$t('Sign-in link')"
                    :hint="
                        $t('Share it, or put it in your provider’s app list.')
                    "
                >
                    <CopyField
                        :label="$t('This workspace')"
                        :value="directUrl"
                    />
                </SettingsLine>
                <SettingsLine
                    :label="$t('Callback host')"
                    :hint="
                        $t(
                            'Every redirect, ACS and metadata URL is on this one host, whatever address people use.',
                        )
                    "
                >
                    <p class="font-ui-mono text-[12.5px] md:pt-1.5">
                        {{ callbackHost }}
                    </p>
                </SettingsLine>
            </div>
        </Tile>

        <Tile
            v-for="c in [...(instance ? [instance] : []), ...connections]"
            :id="`connection-${c.id}`"
            :key="c.id"
            class="scroll-mt-6"
            :title="c.name"
            :description="
                c.instance
                    ? $t('Set for the whole install in .env. Change it there.')
                    : `${$t(c.presetLabel)} · ${c.protocol === 'saml' ? 'SAML 2.0' : 'OpenID Connect'}`
            "
            :padded="false"
        >
            <template #actions>
                <span
                    :class="[
                        pill,
                        c.enabled
                            ? 'bg-(--ds-green-bg) text-(--ds-green)'
                            : 'bg-(--ds-active) text-(--ds-text-muted)',
                    ]"
                    >{{ c.enabled ? $t('On') : $t('Off') }}</span
                >
            </template>

            <ConnectionForm
                v-if="editing === c.id"
                :connection="c"
                :presets="presets"
                :roles="roles"
                :domains="emailDomains.domains"
                :submit-url="connectionUrl(c)"
                @done="editing = null"
            />

            <div v-else class="ds-tile-rows">
                <SettingsLine
                    :label="$t('For your identity provider')"
                    :hint="$t('Paste these into the app you made there.')"
                >
                    <CopyField
                        v-for="u in c.urls"
                        :key="u.label"
                        :label="$t(u.label)"
                        :value="u.url"
                    />
                </SettingsLine>

                <SettingsLine :label="$t('Domains')">
                    <p
                        v-if="c.domains.length"
                        class="font-ui-mono text-[12.5px] md:pt-1.5"
                    >
                        {{ c.domains.join(', ') }}
                    </p>
                    <p v-else :class="muted" class="md:pt-1.5">
                        {{
                            $t(
                                'None yet. Add a verified domain so people can find it.',
                            )
                        }}
                    </p>
                </SettingsLine>

                <SettingsLine
                    :label="$t('Groups to roles')"
                    :hint="
                        $t(
                            'Applied at every sign-in. The highest role wins; the owner keeps theirs.',
                        )
                    "
                >
                    <table
                        v-if="c.groupMappings.length"
                        class="w-full text-[13px]/5"
                    >
                        <tbody>
                            <tr
                                v-for="m in c.groupMappings"
                                :key="m.id"
                                class="border-b border-(--ds-divider)"
                            >
                                <td class="py-1.5 font-ui-mono text-[12.5px]">
                                    {{ m.group }}
                                </td>
                                <td class="py-1.5">
                                    {{ $t(roleLabel(m.role)) }}
                                </td>
                                <td class="py-1.5 text-right">
                                    <AppButton
                                        v-if="!c.instance"
                                        size="xs"
                                        variant="quiet"
                                        @click="removeMapping(c, m.id)"
                                        >{{ $t('Remove') }}</AppButton
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <form
                        v-if="!c.instance"
                        class="flex flex-wrap gap-2"
                        @submit.prevent="addMapping(c)"
                    >
                        <Input
                            v-model="mapping.group"
                            :placeholder="$t('Group name or ID')"
                            class="min-w-48 flex-1 font-ui-mono text-[12.5px]"
                            :aria-label="$t('Group name or ID')"
                        />
                        <select
                            v-model="mapping.role"
                            :class="select"
                            :aria-label="$t('Role')"
                        >
                            <option
                                v-for="r in roles"
                                :key="r.value"
                                :value="r.value"
                            >
                                {{ $t(r.label) }}
                            </option>
                        </select>
                        <AppButton
                            type="submit"
                            size="sm"
                            :disabled="mapping.processing"
                        >
                            {{ $t('Map') }}
                        </AppButton>
                    </form>
                    <InputError
                        :message="mapping.errors.group ?? mapping.errors.role"
                    />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Test')"
                    :hint="
                        $t(
                            'Signs in at the provider without signing anyone in here, and shows what came back.',
                        )
                    "
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <a
                            :class="appButtonClasses('secondary', 'sm')"
                            :href="c.testUrl"
                            >{{ $t('Test connection') }}</a
                        >
                        <span
                            v-if="c.lastTest"
                            :class="[
                                pill,
                                c.lastTest.ok
                                    ? 'bg-(--ds-green-bg) text-(--ds-green)'
                                    : 'bg-(--ds-amber-bg) text-(--ds-amber)',
                            ]"
                        >
                            {{
                                c.lastTest.ok ? $t('Worked') : $t('Didn’t work')
                            }}
                        </span>
                    </div>
                    <div
                        v-if="c.lastTest"
                        class="flex flex-col gap-1 text-[13px]/5"
                    >
                        <p v-if="c.lastTest.error" class="text-(--ds-red)">
                            {{ c.lastTest.error }}
                        </p>
                        <template v-else>
                            <p>
                                <span :class="muted">{{ $t('Email') }}:</span>
                                {{ c.lastTest.email || $t('none') }}
                                <span :class="muted">
                                    ({{
                                        c.lastTest.emailVerified
                                            ? $t('verified by the provider')
                                            : $t('not verified')
                                    }})
                                </span>
                            </p>
                            <p>
                                <span :class="muted"
                                    >{{ $t('Subject') }}:</span
                                > <span class="font-ui-mono text-[12.5px]">{{
                                    c.lastTest.subject
                                }}</span>
                            </p>
                            <p v-if="c.lastTest.name">
                                <span :class="muted">{{ $t('Name') }}:</span>
                                {{ c.lastTest.name }}
                            </p>
                            <p>
                                <span :class="muted">{{ $t('Groups') }}:</span>
                                {{
                                    c.lastTest.groups?.length
                                        ? c.lastTest.groups.join(', ')
                                        : $t('none')
                                }}
                            </p>
                            <p v-if="c.lastTest.plan">
                                {{ $t(planText[c.lastTest.plan] ?? '') }}
                                <span
                                    v-if="
                                        c.lastTest.role &&
                                        c.lastTest.plan !== 'refuse'
                                    "
                                    >{{
                                        $t('Role: :role', {
                                            role: $t(
                                                roleLabel(c.lastTest.role),
                                            ),
                                        })
                                    }}</span
                                >
                                <span
                                    v-if="c.lastTest.reason"
                                    class="text-(--ds-amber)"
                                    >{{ c.lastTest.reason }}</span
                                >
                            </p>
                            <button
                                v-if="c.lastTest.claims"
                                type="button"
                                class="w-fit cursor-pointer text-[12.5px] text-(--ds-text-muted) hover:text-(--ds-text)"
                                @click="
                                    showClaims =
                                        showClaims === c.id ? null : c.id
                                "
                            >
                                {{
                                    showClaims === c.id
                                        ? $t('Hide all claims')
                                        : $t('Show all claims')
                                }}
                            </button>
                            <pre
                                v-if="showClaims === c.id"
                                class="max-h-80 overflow-auto rounded-lg border border-(--ds-border) bg-(--ds-field) p-3 font-ui-mono text-[12px]/5"
                                >{{ c.lastTest.claims }}</pre>
                        </template>
                    </div>
                </SettingsLine>

                <div
                    v-if="!c.instance"
                    class="ds-tile-row flex gap-2 px-5 py-3"
                >
                    <AppButton size="sm" @click="editing = c.id">{{
                        $t('Edit')
                    }}</AppButton>
                    <AppButton size="sm" variant="danger" @click="remove(c)">{{
                        $t('Remove')
                    }}</AppButton>
                    <span :class="muted" class="ml-auto self-center">
                        {{
                            $t(
                                c.identities === 1
                                    ? ':count person linked'
                                    : ':count people linked',
                                { count: String(c.identities) },
                            )
                        }}
                    </span>
                </div>
            </div>
        </Tile>

        <Tile
            v-if="adding"
            :title="$t('Add a connection')"
            :description="
                $t(
                    'Pick your provider. Each preset only fills in the form; they all run on the same OpenID Connect or SAML code.',
                )
            "
            :padded="false"
        >
            <ConnectionForm
                :presets="presets"
                :roles="roles"
                :domains="emailDomains.domains"
                :submit-url="`${base}/connections`"
                @done="adding = false"
            />
        </Tile>
        <div v-else>
            <AppButton variant="primary" @click="adding = true">{{
                $t('Add a connection')
            }}</AppButton>
        </div>

        <Tile
            v-if="scim"
            :title="$t('SCIM provisioning')"
            :description="
                $t(
                    'Preview. Your identity provider adds, updates and removes people, and their groups map to roles through the mappings above.',
                )
            "
            :padded="false"
        >
            <div class="ds-tile-rows">
                <SettingsLine :label="$t('SCIM base URL')">
                    <CopyField :label="$t('Base URL')" :value="scim.baseUrl" />
                </SettingsLine>
                <SettingsLine
                    :label="$t('Token')"
                    :hint="
                        scim.hasToken
                            ? scim.lastUsedAt
                                ? $t('Last used :when.', {
                                      when: new Date(
                                          scim.lastUsedAt,
                                      ).toLocaleString(),
                                  })
                                : $t('Not used yet.')
                            : $t('None yet.')
                    "
                >
                    <CopyField
                        v-if="scimToken"
                        :label="$t('Copy it now; it won’t be shown again.')"
                        :value="scimToken"
                    />
                    <div class="flex gap-2">
                        <AppButton size="sm" @click="newScimToken">
                            {{
                                scim.hasToken
                                    ? $t('Make a new token')
                                    : $t('Make a token')
                            }}
                        </AppButton>
                        <AppButton
                            v-if="scim.hasToken"
                            size="sm"
                            variant="danger"
                            @click="revokeScimToken"
                        >
                            {{ $t('Revoke') }}
                        </AppButton>
                    </div>
                </SettingsLine>
            </div>
        </Tile>

        <VerifiedDomains :email-domains="emailDomains" />
    </div>
</template>
