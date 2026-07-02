<script setup>
/**
 * Civic/PrivateRooms — the MESSAGES inbox (mockups/v3/groups/groups-home.html contract, Phase 3d):
 * direct & group messages as a thin UI over the EXISTING private-room primitive (SocialSpace
 * group/is_private + SocialMembership). Operator-settled language: "direct & group messages" —
 * never "party". After starting one, the "Bring people in" panel mints the room's invite link
 * (InviteButton, kind=space) — THE distribution mechanism; there is no user directory by design
 * (pseudonymous). Rooms are OFF the public record: only the people in the room can read them
 * (the "Art. I private half").
 */
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import Card from '@/Components/Ui/Card.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import InviteButton from '@/Components/Invite/InviteButton.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

defineProps({
    rooms: { type: Array, default: () => [] },  // [{ id, title, is_owner, memberCount, openedAt }]
    created: { type: Object, default: null },   // the just-created room — opens "Bring people in"
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

// Start a direct message — a 2-person-intended room. The title is a friendly default, editable.
const dm = useForm({ name: 'You & a friend' });
function startDm() {
    dm.post('/civic/rooms', { onSuccess: () => dm.reset('name') });
}

// Start a group — a few people, one conversation.
const group = useForm({ name: '' });
function startGroup() {
    group.post('/civic/rooms', { onSuccess: () => group.reset('name') });
}

function openedLabel(iso) {
    if (!iso) return null;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return null;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>

<template>
    <Head title="Messages" />

    <div class="space-y-4">
        <header>
            <p class="text-xs uppercase tracking-wide opacity-60">People, together · direct &amp; group messages</p>
            <h1 class="text-xl font-semibold">Messages</h1>
            <p class="text-sm opacity-70">
                Direct and group messages — private, like a ballot; only the people in the room can read them.
            </p>
        </header>

        <div v-if="flashStatus" class="text-sm text-emerald-700 dark:text-emerald-400" role="status">
            {{ flashStatus }}
        </div>

        <!-- The share step: right after creating a conversation, bring people in with a link. -->
        <Card v-if="created">
            <div class="space-y-2">
                <h2 class="text-base font-semibold">Bring people in</h2>
                <p class="text-sm opacity-70">
                    “{{ created.title }}” is ready. Share this link — whoever opens it lands in this room with a
                    seat saved.
                </p>
                <InviteButton :spec="{ kind: 'space', space_id: created.id }" label="Create the invite link" />
                <p class="text-sm">
                    <Link :href="`/civic/rooms/${created.id}`" class="underline">Open the conversation</Link>
                </p>
            </div>
        </Card>

        <div class="grid gap-4 sm:grid-cols-2">
            <Card>
                <form @submit.prevent="startDm" class="space-y-3">
                    <h2 class="text-base font-semibold">Start a direct message</h2>
                    <p class="text-sm opacity-70">One person, one conversation — you share the link, they land in it.</p>
                    <Field label="Title" :error="dm.errors.name">
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="dm.name"
                                type="text"
                                class="field-input w-full"
                                maxlength="200"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                    <Btn type="submit" variant="primary" :disabled="dm.processing || !dm.name.trim()">
                        Start a direct message
                    </Btn>
                </form>
            </Card>

            <Card>
                <form @submit.prevent="startGroup" class="space-y-3">
                    <h2 class="text-base font-semibold">Start a group</h2>
                    <p class="text-sm opacity-70">A few people together — talk, files, voice, and video.</p>
                    <Field label="Group name" :error="group.errors.name">
                        <template #control="{ id, invalid, describedBy }">
                            <input
                                :id="id"
                                v-model="group.name"
                                type="text"
                                class="field-input w-full"
                                maxlength="200"
                                placeholder="e.g. Saturday crew"
                                :aria-invalid="invalid ? 'true' : undefined"
                                :aria-describedby="describedBy"
                            />
                        </template>
                    </Field>
                    <Btn type="submit" variant="primary" :disabled="group.processing || !group.name.trim()">
                        Start a group
                    </Btn>
                </form>
            </Card>
        </div>

        <Card v-if="rooms.length">
            <h2 class="mb-2 text-sm font-semibold">Your conversations</h2>
            <ul class="divide-y divide-black/5 dark:divide-white/10">
                <li v-for="r in rooms" :key="r.id" class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <Link :href="`/civic/rooms/${r.id}`" class="font-medium hover:underline">{{ r.title }}</Link>
                    <span class="flex flex-wrap items-center gap-2 text-xs opacity-70">
                        <span class="rounded bg-black/5 px-2 py-0.5 dark:bg-white/10">{{ r.is_owner ? 'owner' : 'member' }}</span>
                        <span>{{ r.memberCount === 1 ? 'just you' : `${r.memberCount} people` }}</span>
                        <span v-if="openedLabel(r.openedAt)">· opened {{ openedLabel(r.openedAt) }}</span>
                    </span>
                </li>
            </ul>
        </Card>
        <p v-else class="text-sm opacity-60">No messages yet — start a direct message or a group above.</p>

        <p class="text-xs opacity-60">
            A group message grants no governance power and is off the public record — nothing here is testimony
            or sealed. Only the people in the room can read it; when you need someone, share a link.
        </p>
    </div>
</template>
