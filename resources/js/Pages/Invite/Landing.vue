<script setup>
/**
 * Invite/Landing — the public front door for a shared invite (/i/{token}). A guest who opens a
 * friend's link lands here: a short preview of where they're headed + sign up / log in. The
 * destination is already held server-side (url.intended), so both buttons continue there once they
 * have an account. An invalid/expired link still shows the door — never a dead end. A signed-in
 * user is redirected straight through and never sees this page.
 */
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
defineOptions({ layout: null });
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';

const props = defineProps({
    // { label, kind, inviter, path } — or null when the link is invalid / expired / revoked.
    invite: { type: Object, default: null },
});

const KIND_NOUN = {
    call: 'a live call',
    commons: 'the public square',
    proceeding: 'a public proceeding',
    space: 'a space',
};
const kindNoun = computed(() => KIND_NOUN[props.invite?.kind] ?? 'the conversation');
</script>

<template>
    <Head title="You’re invited" />

    <main id="main" class="invite-page">
        <div class="stack">
            <Card v-if="invite" as="section" aria-labelledby="inv-h">
                <template #title>
                    <h1 id="inv-h">You’re invited</h1>
                </template>

                <p class="lede">
                    <template v-if="invite.inviter"><strong>{{ invite.inviter }}</strong> invited you to join </template>
                    <template v-else>You’ve been invited to join </template>
                    <strong>{{ invite.label || kindNoun }}</strong>.
                </p>
                <p class="cc-small">
                    Create an account (or log in) and you’ll go straight there. Anyone can join — being a
                    person is the only requirement; your rights ride with your residency, not this link.
                </p>

                <div class="cluster">
                    <Btn as="a" href="/register" variant="primary">Sign up &amp; join</Btn>
                    <Btn as="a" href="/login" variant="ghost">Log in</Btn>
                </div>

                <p class="citation">Open to any person · Art. I</p>
            </Card>

            <Card v-else as="section" aria-labelledby="inv-x">
                <template #title>
                    <h1 id="inv-x">This invite link has expired</h1>
                </template>
                <p class="lede">That link is no longer valid — but you can still join.</p>
                <div class="cluster">
                    <Btn as="a" href="/register" variant="primary">Create an account</Btn>
                    <Btn as="a" href="/" variant="ghost">Explore first</Btn>
                </div>
            </Card>
        </div>
    </main>
</template>

<style scoped>
.invite-page {
    min-height: 100vh;
    max-inline-size: 34rem;
    margin-inline: auto;
    padding: var(--space-7) var(--space-4);
    display: grid;
    align-content: center;
}
.lede {
    font-size: 1.1rem;
    margin-block-end: var(--space-2);
}
</style>
