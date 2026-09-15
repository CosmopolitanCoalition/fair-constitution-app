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
import { useI18n } from 'vue-i18n';
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

const { t } = useI18n();

const kindNoun = computed(() => {
    switch (props.invite?.kind) {
        case 'call': return t('c_front.landing.kind_call', 'a live call');
        case 'commons': return t('c_front.landing.kind_commons', 'the public square');
        case 'proceeding': return t('c_front.landing.kind_proceeding', 'a public proceeding');
        case 'space': return t('c_front.landing.kind_space', 'a private room');
        default: return t('c_front.landing.kind_default', 'the conversation');
    }
});
const isSpace = computed(() => props.invite?.kind === 'space');
const destinationName = computed(() => props.invite?.label || kindNoun.value);
const heading = computed(() =>
    props.invite?.inviter
        ? t('c_front.landing.heading_invited_by', { inviter: props.invite.inviter })
        : t('c_front.landing.heading_invited', 'You’re invited')
);
/**
 * ROSTER SIZE, SAID AS ROSTER SIZE. This used to read "N people in this room",
 * which a guest reads as presence — how many are in there NOW. It never was:
 * preview.memberCount is SocialSpace::memberships()->count(), a membership list
 * length. The app has no presence source at all (checked: social_memberships
 * carries no last_seen/online, LiveFloorService holds only floorHolder/queue/
 * speaking, LiveRoomController hard-codes online=false, LiveKitTokenService
 * mints tokens and keeps no roster, and useLiveRoom is a poll/freshness layer
 * that re-fetches props and computes nothing). So the honest move is to name
 * what the number IS and say plainly that presence isn't shown — never to dress
 * a roster count as a live headcount.
 *
 * null for call / commons / proceeding invites, which have no roster: render
 * nothing rather than "0 people".
 */
const memberLine = computed(() => {
    const n = props.preview?.memberCount;
    if (n === null || n === undefined) return null;
    return n === 1 ? t('c_front.landing.member_one', '1 member') : t('c_front.landing.member_many', { n });
});
</script>

<template>
    <Head :title="t('c_front.landing.head_title', 'You’re invited')" />

    <main id="main" class="invite-page">
        <div class="stack">
            <template v-if="invite">
                <header class="invite-header">
                    <span class="eyebrow" data-no-i18n>World of Statecraft</span>
                    <h1>{{ heading }}</h1>
                    <p class="page-intro">
                        <template v-if="isSpace">
                            <strong>{{ destinationName }}</strong> {{ t('c_front.landing.space_intro', 'is a private room — they saved you a seat. Create an account and you’re in.') }}
                        </template>
                        <template v-else>
                            {{ t('c_front.landing.open_intro_before', 'Step in and see what this is —') }} <strong>{{ destinationName }}</strong> {{ t('c_front.landing.open_intro_after', 'is already open. You don’t need an account to watch.') }}
                        </template>
                    </p>
                </header>

                <Card v-if="preview" as="section" aria-labelledby="room-h" :eyebrow="t('c_front.landing.where_leads', 'Where this leads')">
                    <template #title>
                        <h2 id="room-h">{{ preview.title }}</h2>
                    </template>
                    <div class="cluster preview-facts">
                        <span v-if="memberLine" class="cc-small">{{ memberLine }}</span>
                        <!-- pill--info for BOTH, deliberately. This pill states a
                             PRIVACY fact, and pill--live is the green liveness
                             treatment — dressed that way, a permanently-open
                             commons read as "live right now". pill--live and its
                             dotlive stay reserved for a status that genuinely is. -->
                        <span class="pill pill--info">
                            {{ preview.isPrivate ? t('c_front.landing.private_pill', 'A private room — invite only') : t('c_front.landing.public_pill', 'Anyone may watch') }}
                        </span>
                    </div>
                    <p v-if="memberLine" class="citation">
                        {{ t('c_front.landing.roster_note', 'That’s the membership list. Who’s in the room right now isn’t shown until you’re inside.') }}
                    </p>
                </Card>

                <Card as="section" aria-labelledby="cta-h">
                    <h2 id="cta-h" class="visually-hidden">{{ t('c_front.landing.step_in', 'Step in') }}</h2>
                    <div class="cluster">
                        <Btn as="a" href="/register" variant="primary">{{ t('c_front.landing.signup_join', 'Sign up & join') }}</Btn>
                        <Btn as="a" href="/login" variant="secondary">{{ t('c_front.landing.log_in', 'Log in') }}</Btn>
                        <Btn as="a" href="/" variant="ghost">{{ t('c_front.landing.look_around', 'Look around first') }}</Btn>
                    </div>
                    <p class="cc-small" style="margin-block-start: var(--space-2)">
                        {{ t('c_front.landing.land_note', 'Either way you’ll land right where this link points — the destination is already remembered.') }}
                    </p>
                </Card>
            </template>

            <template v-else>
                <header class="invite-header">
                    <span class="eyebrow" data-no-i18n>World of Statecraft</span>
                    <h1>{{ t('c_front.landing.expired_title', 'This invite link has expired') }}</h1>
                    <p class="page-intro">
                        {{ t('c_front.landing.expired_intro', 'That link is no longer valid — but the door is still open. Anyone can join.') }}
                    </p>
                </header>

                <Card as="section" aria-labelledby="dead-h">
                    <h2 id="dead-h" class="visually-hidden">{{ t('c_front.landing.join_anyway', 'Join anyway') }}</h2>
                    <div class="cluster">
                        <Btn as="a" href="/register" variant="primary">{{ t('c_front.landing.create_account', 'Create an account') }}</Btn>
                        <Btn as="a" href="/" variant="ghost">{{ t('c_front.landing.explore_first', 'Explore first') }}</Btn>
                    </div>
                </Card>
            </template>

            <Card as="section" aria-labelledby="honest-h">
                <template #title>
                    <h2 id="honest-h">{{ t('c_front.landing.honest_h', 'What you can do right now') }}</h2>
                </template>
                <ul class="cc-small honest-list">
                    <li>
                        <strong>{{ t('c_front.landing.honest_watch_strong', 'Watch any public room') }}</strong> {{ t('c_front.landing.honest_watch_body', '— every hearing, meeting, and vote happens in the open. The gallery is always free to sit in.') }}
                    </li>
                    <li>
                        <strong>{{ t('c_front.landing.honest_browse_strong', 'Browse everything') }}</strong> {{ t('c_front.landing.honest_browse_body', '— every place on Earth, every record, every election, every law.') }}
                    </li>
                    <li>
                        <strong>{{ t('c_front.landing.honest_voice_strong', 'When you want a voice') }}</strong> {{ t('c_front.landing.honest_voice_mid', '— to speak on the record, vote, or run for office —') }} <a href="/register">{{ t('c_front.landing.honest_voice_link', 'create an account') }}</a> {{ t('c_front.landing.honest_voice_after', 'and say where you live. Living somewhere is the only requirement there is.') }}
                    </li>
                </ul>
                <p class="citation" style="margin-block-start: var(--space-3)">
                    {{ t('c_front.landing.invite_note', 'An invite carries no power — nobody gains a vote, a seat, or an advantage by inviting you. It’s just a door.') }}
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
