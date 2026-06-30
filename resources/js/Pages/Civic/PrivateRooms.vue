<script setup>
/**
 * Civic/PrivateRooms — the player's private rooms (groups / DMs). Start a room here, then invite a
 * friend with a link from inside it. Private rooms are OFF the public record: only invited members
 * can see them; nothing here is testimony or sealed (the "Art. I private half").
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';

defineProps({ rooms: { type: Array, default: () => [] } });

const form = useForm({ name: '' });
function create() {
    form.post('/civic/rooms', { onSuccess: () => form.reset('name') });
}
</script>

<template>
    <Head title="Private rooms" />

    <div class="space-y-4">
        <header>
            <h1 class="text-xl font-semibold">Private rooms</h1>
            <p class="text-sm opacity-70">
                Your private rooms and calls. Start one, then invite a friend with a link — only people you
                invite can join. These are off the public record: nothing here is testimony or sealed.
            </p>
        </header>

        <Card>
            <form @submit.prevent="create" class="flex flex-wrap items-end gap-3">
                <Field label="Room name" :error="form.errors.name" class="flex-1">
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="form.name"
                            type="text"
                            class="field-input w-full"
                            maxlength="200"
                            placeholder="e.g. Friday call"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>
                <Btn type="submit" variant="primary" :disabled="form.processing || !form.name.trim()">Start a room</Btn>
            </form>
        </Card>

        <Card v-if="rooms.length">
            <ul class="divide-y divide-black/5 dark:divide-white/10">
                <li v-for="r in rooms" :key="r.id" class="flex items-center justify-between gap-3 py-2">
                    <Link :href="`/civic/rooms/${r.id}`" class="font-medium hover:underline">{{ r.title }}</Link>
                    <span class="text-xs opacity-60">
                        {{ r.members }} member{{ r.members === 1 ? '' : 's' }}<span v-if="r.is_owner"> · you own this</span>
                    </span>
                </li>
            </ul>
        </Card>
        <p v-else class="text-sm opacity-60">No private rooms yet — start one above.</p>
    </div>
</template>
