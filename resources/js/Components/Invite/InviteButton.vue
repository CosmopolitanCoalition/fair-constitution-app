<script setup>
/**
 * InviteButton — mint a shareable invite link for a destination the current user can already
 * reach, then show it with copy + native share. `spec` is the POST /invites payload (kind +
 * jurisdiction_id/space, or a proceeding path); the server builds the canonical same-origin link
 * and returns it ONCE. Anyone who opens it lands on /i/{token} and can sign up to continue there.
 */
import { ref } from 'vue';
import Btn from '@/Components/Ui/Btn.vue';

const props = defineProps({
    // { kind: 'commons'|'call'|'proceeding', jurisdiction_id?, space?, path?, label?, max_uses?, ttl_days? }
    spec: { type: Object, required: true },
    label: { type: String, default: 'Invite a friend' },
});

const url = ref(null);
const minting = ref(false);
const error = ref(null);
const copied = ref(false);
const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

async function mint() {
    if (minting.value) return;
    minting.value = true;
    error.value = null;
    try {
        const { data } = await window.axios.post('/invites', props.spec);
        url.value = data.url;
    } catch (e) {
        error.value = e?.response?.data?.error ?? 'Could not create an invite link.';
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
        await navigator.share({ title: 'Join me', url: url.value });
    } catch {
        /* the user dismissed the share sheet */
    }
}
</script>

<template>
    <div class="invite-button">
        <Btn v-if="!url" variant="secondary" size="sm" :disabled="minting" @click="mint">
            {{ minting ? 'Creating link…' : label }}
        </Btn>

        <div v-else class="invite-link">
            <input class="field-input invite-url" readonly :value="url" @focus="$event.target.select()" />
            <Btn variant="secondary" size="sm" @click="copy">{{ copied ? 'Copied!' : 'Copy' }}</Btn>
            <Btn v-if="canShare" variant="ghost" size="sm" @click="share">Share</Btn>
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
