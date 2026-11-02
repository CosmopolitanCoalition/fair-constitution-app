<script setup>
/**
 * InviteButton — mint a shareable invite link for a destination the current user can already
 * reach, then show it with copy + native share. `spec` is the POST /invites payload (kind +
 * jurisdiction_id/space, or a proceeding path); the server builds the canonical same-origin link
 * with a short code. Anyone who opens it lands on /i/{code} and can sign up to continue there.
 * The person may choose a custom link name; a custom name is guessable, so it keeps the default
 * 14-day expiry (or the spec's own limits).
 */
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Btn from '@/Components/Ui/Btn.vue';

const { t } = useI18n();

const props = defineProps({
    // { kind: 'commons'|'call'|'proceeding', jurisdiction_id?, space?, path?, label?, max_uses?, ttl_days? }
    spec: { type: Object, required: true },
    label: { type: String, default: '' },
});

const url = ref(null);
const slug = ref('');
const origin = typeof window !== 'undefined' ? window.location.host : '';
const minting = ref(false);
const error = ref(null);
const copied = ref(false);
const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

async function mint() {
    if (minting.value) return;
    minting.value = true;
    error.value = null;
    try {
        const body = slug.value.trim() ? { ...props.spec, slug: slug.value.trim().toLowerCase() } : props.spec;
        const { data } = await window.axios.post('/invites', body);
        url.value = data.url;
    } catch (e) {
        const fieldError = e?.response?.data?.errors?.slug?.[0];
        error.value = e?.response?.data?.error ?? fieldError ?? t('c_civic_components.invite_button.error_default', 'Could not create an invite link.');
    } finally {
        minting.value = false;
    }
}

async function copy() {
    if (!url.value) return;
    try {
        await navigator.clipboard.writeText(url.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1800);
    } catch {
        /* clipboard blocked — the field stays selectable as a fallback */
    }
}

async function share() {
    if (!url.value) return;
    try {
        await navigator.share({ title: t('c_civic_components.invite_button.share_title', 'Join me'), url: url.value });
    } catch {
        /* the user dismissed the share sheet */
    }
}
</script>

<template>
    <div class="invite-button">
        <div v-if="!url" class="invite-make">
            <Btn variant="secondary" size="sm" :disabled="minting" @click="mint">
                {{ minting ? t('c_civic_components.invite_button.creating', 'Creating link…') : (label || t('c_civic_components.invite_button.default_label', 'Invite a friend')) }}
            </Btn>
            <details class="invite-custom">
                <summary>{{ t('c_civic_components.invite_button.custom_summary', 'Choose a link name') }}</summary>
                <label class="invite-custom__field">
                    <span class="invite-custom__prefix">{{ origin }}/i/</span>
                    <input v-model="slug" class="field-input" maxlength="40" autocomplete="off" spellcheck="false" pattern="[a-z0-9\-]*"
                        :aria-label="t('c_civic_components.invite_button.custom_label', 'Custom link name')"
                        :placeholder="t('c_civic_components.invite_button.custom_hint', 'team-night')" />
                </label>
            </details>
        </div>

        <div v-else class="invite-link">
            <input class="field-input invite-url" readonly :value="url" :aria-label="t('c_civic_components.invite_button.invite_link', 'Invite link')" @focus="$event.target.select()" />
            <Btn variant="secondary" size="sm" @click="copy">{{ copied ? t('c_civic_components.invite_button.copied', 'Copied!') : t('c_civic_components.invite_button.copy', 'Copy') }}</Btn>
            <Btn v-if="canShare" variant="ghost" size="sm" @click="share">{{ t('c_civic_components.invite_button.share', 'Share') }}</Btn>
        </div>

        <p v-if="error" class="invite-error">{{ error }}</p>
    </div>
</template>

<style scoped>
.invite-link {
    display: flex;
    gap: var(--space-2);
    align-items: center;
    flex-wrap: wrap;
}
.invite-make { display: flex; gap: var(--space-2); align-items: center; flex-wrap: wrap; }
.invite-custom__field { display: flex; align-items: center; gap: 0.25rem; margin-block-start: var(--space-1); }
.invite-custom__prefix { opacity: 0.7; font-size: 0.85rem; white-space: nowrap; }
.invite-url {
    min-inline-size: 15rem;
    flex: 1 1 18rem;
}
.invite-error {
    color: var(--color-danger, #b91c1c);
    font-size: 0.85rem;
    margin-block-start: var(--space-1);
}
</style>
