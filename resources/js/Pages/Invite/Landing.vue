<script setup>
/**
 * Invite/Landing — the public front door for a shared invite (/i/{token}), wired to the v3
 * arrival contract (mockups/v3/civic/join.html). A guest who opens a friend's link lands here:
 * who invited them, an honest preview of where the link leads, and the ways in. The destination
 * is already held server-side (url.intended), so Sign up / Log in both continue straight there.
 * An invalid/expired link still shows the door — never a dead end. A signed-in user is
 * redirected straight through and never sees this page.
 *
 * DECISION (Phase 3a): the mockup's "What should people call you?" pick-a-name input is
 * deliberately DROPPED from this page — the name is asked exactly ONCE, on the register form
 * (Auth/Register adapts its display-name field when an invite is in flight). This page only
 * opens doors: /register, /login, or look around.
 */
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
defineOptions({ layout: null });
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';

const props = defineProps({
    // { label, kind, inviter, path } — or null when the link is invalid / expired / revoked.
    invite: { type: Object, default: null },
    // { title, memberCount, isPrivate } — an honest look at the destination; null when it
    // couldn't be resolved (the door still works without it).
    preview: { type: Object, default: null },
});

const KIND_NOUN = {
    call: 'a live call',
    commons: 'the public square',
    proceeding: 'a public proceeding',
    space: 'a private room',
};
const kindNoun = computed(() => KIND_NOUN[props.invite?.kind] ?? 'the conversation');
const isSpace = computed(() => props.invite?.kind === 'space');
const destinationName = computed(() => props.invite?.label || kindNoun.value);
const heading = computed(() =>
    props.invite?.inviter ? `${props.invite.inviter} invited you` : 'You’re invited'
);
const memberLine = computed(() => {
    const n = props.preview?.memberCount;
    if (n === null || n === undefined) return null;
    return n === 1 ? '1 person in this room' : `${n} people in this room`;
});
</script>

<template>
    <Head title="You’re invited" />

    <main id="main" class="invite-page">
        <div class="stack">
            <template v-if="invite">
                <header class="invite-header">
                    <span class="eyebrow">World of Statecraft</span>
                    <h1>{{ heading }}</h1>
                    <p class="page-intro">
                        <template v-if="isSpace">
                            <strong>{{ destinationName }}</strong> is a private room — they saved you
                            a seat. Create an account and you’re in.
                        </template>
                        <template v-else>
                            Step in and see what this is — <strong>{{ destinationName }}</strong> is
                            already open. You don’t need an account to watch.
                        </template>
                    </p>
                </header>

                <Card v-if="preview" as="section" aria-labelledby="room-h" eyebrow="Where this leads">
                    <template #title>
                        <h2 id="room-h">{{ preview.title }}</h2>
                    </template>
                    <div class="cluster preview-facts">
                        <span v-if="memberLine" class="cc-small">{{ memberLine }}</span>
                        <span class="pill" :class="preview.isPrivate ? 'pill--info' : 'pill--live'">
                            {{ preview.isPrivate ? 'A private room — invite only' : 'Anyone may watch' }}
                        </span>
                    </div>
                </Card>

                <Card as="section" aria-labelledby="cta-h">
                    <h2 id="cta-h" class="visually-hidden">Step in</h2>
                    <div class="cluster">
                        <Btn as="a" href="/register" variant="primary">Sign up &amp; join</Btn>
                        <Btn as="a" href="/login" variant="secondary">Log in</Btn>
                        <Btn as="a" href="/" variant="ghost">Look around first</Btn>
                    </div>
                    <p class="cc-small" style="margin-block-start: var(--space-2)">
                        Either way you’ll land right where this link points — the destination is
                        already remembered.
                    </p>
                </Card>
            </template>

            <template v-else>
                <header class="invite-header">
                    <span class="eyebrow">World of Statecraft</span>
                    <h1>This invite link has expired</h1>
                    <p class="page-intro">
                        That link is no longer valid — but the door is still open. Anyone can join.
                    </p>
                </header>

                <Card as="section" aria-labelledby="dead-h">
                    <h2 id="dead-h" class="visually-hidden">Join anyway</h2>
                    <div class="cluster">
                        <Btn as="a" href="/register" variant="primary">Create an account</Btn>
                        <Btn as="a" href="/" variant="ghost">Explore first</Btn>
                    </div>
                </Card>
            </template>

            <Card as="section" aria-labelledby="honest-h">
                <template #title>
                    <h2 id="honest-h">What you can do right now</h2>
                </template>
                <ul class="cc-small honest-list">
                    <li>
                        <strong>Watch any public room</strong> — every hearing, meeting, and vote
                        happens in the open. The gallery is always free to sit in.
                    </li>
                    <li>
                        <strong>Browse everything</strong> — every place on Earth, every record,
                        every election, every law.
                    </li>
                    <li>
                        <strong>When you want a voice</strong> — to speak on the record, vote, or run
                        for office — <a href="/register">create an account</a> and say where you
                        live. Living somewhere is the only requirement there is.
                    </li>
                </ul>
                <p class="citation" style="margin-block-start: var(--space-3)">
                    An invite carries no power — nobody gains a vote, a seat, or an advantage by
                    inviting you. It’s just a door.
                </p>
            </Card>
        </div>
    </main>
</template>

<style scoped>
.invite-page {
    min-height: 100vh;
    max-inline-size: 44rem;
    margin-inline: auto;
    padding: var(--space-7) var(--space-4);
    display: grid;
    align-content: center;
}
.invite-header {
    text-align: center;
}
.preview-facts {
    gap: var(--space-4);
    align-items: center;
}
.honest-list {
    margin: 0;
    padding-inline-start: var(--space-5);
}
</style>
