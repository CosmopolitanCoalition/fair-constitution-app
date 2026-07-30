<script setup>
/**
 * Civic/PrivateRooms — the MESSAGES inbox (mockups/v3/groups/groups-home.html contract): direct &
 * group messages as a thin UI over the EXISTING private-room primitive (SocialSpace group/is_private
 * + SocialMembership), in the v3 conversation chrome (.conv-row / .msg-inbox).
 *
 * A row's preview is the room's REAL last message (server-read, degraded to "No messages yet" when a
 * room has no live channel or the homeserver is down — never faked). Unread counts, a live-now dot,
 * and a persisted DM-vs-group KIND have no source table, so the inbox does NOT show them
 * (honest-empty); the real member count carries the DM/group sense instead. Starting a conversation
 * happens on /civic/rooms/new; the "Bring people in" panel then mints the room's invite link
 * (kind=space) — the only way in, since identities are pseudonymous and there is no user directory.
 * Rooms are OFF the public record: only the people in the room can read them (the "Art. I private half").
 */
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import InviteButton from '@/Components/Invite/InviteButton.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

defineProps({
    // [{ id, title, is_owner, memberCount, openedAt, preview, lastAt }]
    rooms: { type: Array, default: () => [] },
    created: { type: Object, default: null }, // the just-created room — opens "Bring people in"
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

// Avatar initials from the conversation title (the room has no separate avatar source).
function initials(title) {
    const t = (title || '?').trim();
    const parts = t.split(/\s+/).filter(Boolean);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return t.slice(0, 2).toUpperCase();
}

// A compact, locale-aware "when" from the last message (falls back to when the room opened).
function whenLabel(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const min = Math.floor((Date.now() - d.getTime()) / 60000);
    if (min < 1) return 'now';
    if (min < 60) return `${min}m`;
    const hr = Math.floor(min / 60);
    if (hr < 24) return `${hr}h`;
    const day = Math.floor(hr / 24);
    if (day < 7) return d.toLocaleDateString(undefined, { weekday: 'short' });
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
</script>

<template>
    <Head title="Messages" />

    <div class="stack" style="gap: var(--space-4)">
        <header>
            <p class="eyebrow">People, together · direct &amp; group messages</p>
            <h1>Messages</h1>
            <p class="page-intro">
                Your direct and group messages — private, like a ballot; only the people in the room can read them.
            </p>
        </header>

        <p v-if="flashStatus" class="gloss" role="status" style="color: var(--status-success-fg)">{{ flashStatus }}</p>

        <!-- Right after creating a conversation: bring people in with a link (the only way in). -->
        <Card v-if="created" as="section">
            <div class="stack" style="gap: var(--space-2)">
                <h2 style="margin: 0">Bring people in</h2>
                <p class="gloss">
                    “{{ created.title }}” is ready. Share this link — whoever opens it lands in this room with a
                    seat saved.
                </p>
                <InviteButton :spec="{ kind: 'space', space_id: created.id }" label="Create the invite link" />
                <p><Link :href="`/civic/rooms/${created.id}`" class="underline">Open the conversation</Link></p>
            </div>
        </Card>

        <div class="cluster" style="justify-content: space-between; align-items: center; gap: var(--space-3)">
            <p class="gloss" style="margin: 0">Talk, files, voice, and video — a conversation, not a place you have to keep.</p>
            <Link href="/civic/rooms/new" class="btn btn--primary btn--sm">
                <Icon name="plus" size="sm" /> New message
            </Link>
        </div>

        <section v-if="rooms.length" class="card" style="padding: 0" aria-label="Conversations">
            <div class="msg-inbox">
                <Link v-for="r in rooms" :key="r.id" class="conv-row" :href="`/civic/rooms/${r.id}`">
                    <span class="conv-avatar" aria-hidden="true">{{ initials(r.title) }}</span>
                    <span class="conv-main">
                        <span class="conv-top">
                            <strong>{{ r.title }}</strong>
                            <span class="conv-when">{{ whenLabel(r.lastAt || r.openedAt) }}</span>
                        </span>
                        <span class="conv-sub">
                            <Icon :name="r.memberCount > 1 ? 'users' : 'user'" size="sm" />
                            {{ r.memberCount === 1 ? 'Just you' : `${r.memberCount} people` }}
                            · {{ r.is_owner ? 'you own this' : 'member' }}
                        </span>
                        <span class="conv-preview">{{ r.preview || 'No messages yet' }}</span>
                    </span>
                </Link>
            </div>
        </section>
        <p v-else class="gloss">
            No messages yet — <Link href="/civic/rooms/new" class="underline">start a conversation</Link>.
        </p>

        <div class="lr-note">
            <div><Icon name="shield" size="sm" /></div>
            <div>
                <strong style="color: var(--gov-fg)">A group message is just people talking.</strong>
                It is temporary, it grants no governance power, and it is private to its members — nobody else can
                read it. If a group wants to last, it can become an
                <Link href="/organizations" class="underline">organization</Link> — but it never has to.
            </div>
        </div>
    </div>
</template>
