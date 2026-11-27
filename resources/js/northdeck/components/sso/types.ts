export type SsoPreset = {
    key: string;
    label: string;
    protocol: 'oidc' | 'saml';
    issuer: string;
    hint: string;
    docs: string;
    scopes?: string;
    groups_claim?: string;
};

export type SsoRole = { value: string; label: string };

export type SsoTest = {
    ok: boolean;
    error: string | null;
    subject: string | null;
    email: string | null;
    emailVerified: boolean | null;
    name: string | null;
    groups: string[] | null;
    role: string | null;
    plan: 'sign_in' | 'link' | 'create' | 'refuse' | null;
    reason: string | null;
    claims: string | null;
};

export type SsoConnection = {
    id: string;
    name: string;
    protocol: 'oidc' | 'saml';
    preset: string;
    presetLabel: string;
    enabled: boolean;
    instance: boolean;
    settings: Record<string, string | boolean | string[] | null>;
    hasClientSecret: boolean;
    jit: boolean;
    defaultRole: string;
    domainIds: number[];
    domains: string[];
    urls: { label: string; url: string }[];
    groupMappings: { id: number; group: string; role: string }[];
    testUrl: string;
    lastTestedAt: string | null;
    lastTest: SsoTest | null;
    identities: number;
};
