<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import type { EmailDomain } from '@/components/workspace/VerifiedDomains.vue';
import type { SsoConnection, SsoPreset, SsoRole } from './types';

const props = defineProps<{
    connection?: SsoConnection;
    presets: SsoPreset[];
    roles: SsoRole[];
    domains: EmailDomain[];
    submitUrl: string;
}>();

const emit = defineEmits<{ done: [] }>();

const text = (key: string, fallback = ''): string => {
    const value = props.connection?.settings[key];

    return typeof value === 'string' ? value : fallback;
};

const flag = (key: string, fallback: boolean): boolean => {
    const value = props.connection?.settings[key];

    return typeof value === 'boolean' ? value : fallback;
};

const form = useForm({
    preset: props.connection?.preset ?? 'oidc',
    name: props.connection?.name ?? '',
    enabled: props.connection?.enabled ?? true,
    jit: props.connection?.jit ?? true,
    single_logout: flag('single_logout', true),
    default_role: props.connection?.defaultRole ?? 'member',
    domain_ids: [...(props.connection?.domainIds ?? [])] as number[],
    settings: {
        issuer: text('issuer'),
        tenant: text('tenant'),
        hosted_domain: text('hosted_domain'),
        client_id: text('client_id'),
        scopes: text('scopes', 'openid email profile'),
        email_claim: text('email_claim'),
        name_claim: text('name_claim'),
        groups_claim: text('groups_claim', 'groups'),
        metadata_url: text('metadata_url'),
        idp_entity_id: text('idp_entity_id'),
        idp_sso_url: text('idp_sso_url'),
        idp_slo_url: text('idp_slo_url'),
        email_attribute: text('email_attribute'),
        name_attribute: text('name_attribute'),
        subject_attribute: text('subject_attribute'),
        name_id_format: text('name_id_format'),
    } as Record<string, string>,
    client_secret: '',
    idp_metadata_xml: '',
    idp_certificate: '',
    want_encrypted: flag('want_encrypted', false),
    allow_idp_initiated: flag('allow_idp_initiated', true),
});

const preset = computed(
    () => props.presets.find((p) => p.key === form.preset) ?? props.presets[0],
);
const saml = computed(() => preset.value?.protocol === 'saml');
const verifiedDomains = computed(() => props.domains.filter((d) => d.verified));
const advanced = ref(false);

function pickPreset(key: string): void {
    const next = props.presets.find((p) => p.key === key);
    form.preset = key;
    if (!next || props.connection) return;
    if (!form.name) form.name = next.label;
    form.settings.issuer = next.issuer.includes('{') ? '' : next.issuer;
    form.settings.scopes = next.scopes ?? 'openid email profile';
    form.settings.groups_claim = next.groups_claim ?? 'groups';
}

function toggleDomain(id: number, on: boolean): void {
    form.domain_ids = on
        ? [...new Set([...form.domain_ids, id])]
        : form.domain_ids.filter((d) => d !== id);
}

async function readMetadata(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    form.idp_metadata_xml = file ? await file.text() : '';
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            form.client_secret = '';
            form.idp_metadata_xml = '';
            form.idp_certificate = '';
            emit('done');
        },
    };
    if (props.connection) form.put(props.submitUrl, options);
    else form.post(props.submitUrl, options);
}

const field = 'font-ui-mono text-[12.5px]';
const select =
    'h-9 w-full rounded-md border border-(--ds-border) bg-(--ds-field) px-2.5 text-[13px] focus-visible:outline-2 focus-visible:outline-(--ds-ring)';
const muted = 'text-[12.5px]/5 text-(--ds-text-muted)';
</script>

<template>
    <form class="ds-tile-rows" @submit.prevent="submit">
        <SettingsLine
            v-if="!connection"
            :label="$t('Identity provider')"
            :hint="preset?.hint"
            input-id="sso-preset"
        >
            <select
                id="sso-preset"
                :class="select"
                :value="form.preset"
                @change="pickPreset(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="p in presets" :key="p.key" :value="p.key">
                    {{ $t(p.label) }}
                </option>
            </select>
        </SettingsLine>

        <SettingsLine
            :label="$t('Name')"
            :hint="$t('Shown on the sign-in button.')"
            input-id="sso-name"
        >
            <Input id="sso-name" v-model="form.name" maxlength="100" />
            <InputError :message="form.errors.name" />
        </SettingsLine>

        <template v-if="!saml">
            <SettingsLine
                v-if="form.preset === 'entra'"
                :label="$t('Tenant ID')"
                :hint="
                    $t(
                        'From Entra ID › Overview. Use organizations only if people from other tenants should get in.',
                    )
                "
                input-id="sso-tenant"
            >
                <Input
                    id="sso-tenant"
                    v-model="form.settings.tenant"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                v-else
                :label="$t('Issuer URL')"
                :hint="
                    $t('We read :path from it.', {
                        path: '/.well-known/openid-configuration',
                    })
                "
                input-id="sso-issuer"
            >
                <Input
                    id="sso-issuer"
                    v-model="form.settings.issuer"
                    :placeholder="preset?.issuer"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                v-if="form.preset === 'google'"
                :label="$t('Hosted domain')"
                :hint="$t('Only Google accounts on this domain can sign in.')"
                input-id="sso-hd"
            >
                <Input
                    id="sso-hd"
                    v-model="form.settings.hosted_domain"
                    placeholder="example.com"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine :label="$t('Client ID')" input-id="sso-client-id">
                <Input
                    id="sso-client-id"
                    v-model="form.settings.client_id"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Client secret')"
                :hint="
                    connection?.hasClientSecret
                        ? $t('Saved. Leave it empty to keep it.')
                        : $t('Leave it empty for a public client (PKCE only).')
                "
                input-id="sso-secret"
            >
                <Input
                    id="sso-secret"
                    v-model="form.client_secret"
                    type="password"
                    autocomplete="off"
                    :placeholder="connection?.hasClientSecret ? '••••••••' : ''"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine :label="$t('Scopes')" input-id="sso-scopes">
                <Input
                    id="sso-scopes"
                    v-model="form.settings.scopes"
                    :class="field"
                />
            </SettingsLine>
        </template>

        <template v-else>
            <SettingsLine
                :label="$t('Metadata URL')"
                :hint="
                    $t(
                        'Or upload the metadata file, or fill in the fields under More settings.',
                    )
                "
                input-id="sso-metadata-url"
            >
                <Input
                    id="sso-metadata-url"
                    v-model="form.settings.metadata_url"
                    placeholder="https://idp.example.com/metadata"
                    :class="field"
                />
                <input
                    type="file"
                    accept=".xml,application/xml,text/xml"
                    :aria-label="$t('Upload metadata XML')"
                    class="text-[12.5px]"
                    @change="readMetadata"
                />
                <p v-if="text('idp_entity_id')" :class="muted">
                    {{
                        $t('Using :entity.', { entity: text('idp_entity_id') })
                    }}
                </p>
            </SettingsLine>
            <SettingsLine
                :label="$t('Encrypted assertions')"
                :hint="
                    $t(
                        'Refuse assertions that aren’t encrypted to our certificate. They must be signed either way.',
                    )
                "
            >
                <DashSwitch
                    v-model="form.want_encrypted"
                    :label="$t('Encrypted assertions')"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Sign-ins started at the provider')"
                :hint="
                    $t(
                        'Let people start from the identity provider’s app list.',
                    )
                "
            >
                <DashSwitch
                    v-model="form.allow_idp_initiated"
                    :label="$t('Sign-ins started at the provider')"
                />
            </SettingsLine>
        </template>

        <SettingsLine
            :label="$t('Domains')"
            :hint="
                $t(
                    'People with an email on these domains are sent here, and only they can be linked or added.',
                )
            "
        >
            <p v-if="!verifiedDomains.length" :class="muted" class="md:pt-1.5">
                {{ $t('Verify a domain below first.') }}
            </p>
            <label
                v-for="domain in verifiedDomains"
                :key="domain.id"
                class="flex items-center gap-2 text-[13px]"
            >
                <input
                    type="checkbox"
                    :checked="form.domain_ids.includes(domain.id)"
                    @change="
                        toggleDomain(
                            domain.id,
                            ($event.target as HTMLInputElement).checked,
                        )
                    "
                />
                <span class="font-ui-mono">{{ domain.domain }}</span>
            </label>
            <InputError :message="form.errors.domain_ids" />
        </SettingsLine>

        <SettingsLine
            :label="$t('Add people when they first sign in')"
            :hint="
                $t(
                    'Off: only people who already have an account or an invite can sign in.',
                )
            "
        >
            <DashSwitch
                v-model="form.jit"
                :label="$t('Add people when they first sign in')"
            />
        </SettingsLine>

        <SettingsLine
            :label="$t('Role for new people')"
            :hint="$t('Unless one of their groups maps to a role.')"
            input-id="sso-role"
        >
            <select id="sso-role" v-model="form.default_role" :class="select">
                <option
                    v-for="role in roles"
                    :key="role.value"
                    :value="role.value"
                >
                    {{ $t(role.label) }}
                </option>
            </select>
            <InputError :message="form.errors.default_role" />
        </SettingsLine>

        <SettingsLine
            :label="$t('Sign out at the provider too')"
            :hint="$t('When the provider supports single logout.')"
        >
            <DashSwitch
                v-model="form.single_logout"
                :label="$t('Sign out at the provider too')"
            />
        </SettingsLine>

        <SettingsLine v-if="connection" :label="$t('On')">
            <DashSwitch v-model="form.enabled" :label="$t('On')" />
        </SettingsLine>

        <div class="ds-tile-row px-5 py-3">
            <button
                type="button"
                class="cursor-pointer text-[12.5px] text-(--ds-text-muted) hover:text-(--ds-text)"
                :aria-expanded="advanced"
                @click="advanced = !advanced"
            >
                {{ advanced ? $t('Fewer settings') : $t('More settings') }}
            </button>
        </div>

        <template v-if="advanced && !saml">
            <SettingsLine :label="$t('Email claim')" input-id="sso-email-claim">
                <Input
                    id="sso-email-claim"
                    v-model="form.settings.email_claim"
                    placeholder="email"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine :label="$t('Name claim')" input-id="sso-name-claim">
                <Input
                    id="sso-name-claim"
                    v-model="form.settings.name_claim"
                    placeholder="name"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Groups claim')"
                input-id="sso-groups-claim"
            >
                <Input
                    id="sso-groups-claim"
                    v-model="form.settings.groups_claim"
                    placeholder="groups"
                    :class="field"
                />
            </SettingsLine>
        </template>

        <template v-if="advanced && saml">
            <SettingsLine
                :label="$t('Identity provider entity ID')"
                input-id="sso-idp-entity"
            >
                <Input
                    id="sso-idp-entity"
                    v-model="form.settings.idp_entity_id"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Sign-in URL (SSO)')"
                input-id="sso-idp-sso"
            >
                <Input
                    id="sso-idp-sso"
                    v-model="form.settings.idp_sso_url"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Logout URL (SLO)')"
                input-id="sso-idp-slo"
            >
                <Input
                    id="sso-idp-slo"
                    v-model="form.settings.idp_slo_url"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Signing certificate')"
                :hint="
                    $t(
                        'PEM or base64. Leave it empty to keep the one you have.',
                    )
                "
                input-id="sso-idp-cert"
            >
                <textarea
                    id="sso-idp-cert"
                    v-model="form.idp_certificate"
                    rows="4"
                    class="w-full rounded-md border border-(--ds-border) bg-(--ds-field) p-2 font-ui-mono text-[12px]"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Email attribute')"
                input-id="sso-email-attr"
            >
                <Input
                    id="sso-email-attr"
                    v-model="form.settings.email_attribute"
                    placeholder="email"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Name attribute')"
                input-id="sso-name-attr"
            >
                <Input
                    id="sso-name-attr"
                    v-model="form.settings.name_attribute"
                    placeholder="displayName"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Groups attribute')"
                input-id="sso-groups-attr"
            >
                <Input
                    id="sso-groups-attr"
                    v-model="form.settings.groups_claim"
                    placeholder="groups"
                    :class="field"
                />
            </SettingsLine>
            <SettingsLine
                :label="$t('Subject attribute')"
                :hint="
                    $t(
                        'Empty uses the NameID. It must never change for a person.',
                    )
                "
                input-id="sso-subject-attr"
            >
                <Input
                    id="sso-subject-attr"
                    v-model="form.settings.subject_attribute"
                    :class="field"
                />
            </SettingsLine>
        </template>

        <div class="ds-tile-row flex flex-col gap-2 px-5 py-4">
            <InputError :message="form.errors.settings" />
            <div class="flex gap-2">
                <AppButton
                    type="submit"
                    variant="primary"
                    :disabled="form.processing"
                >
                    {{ connection ? $t('Save') : $t('Add connection') }}
                </AppButton>
                <AppButton variant="quiet" @click="emit('done')">
                    {{ $t('Cancel') }}
                </AppButton>
            </div>
        </div>
    </form>
</template>
