<script setup>
/**
 * Civic/PrivateRoomCreate — the "New message" page (mockups/v3/groups/group-create.html contract):
 * name a conversation and start it. Direct or group, it is just a conversation — talk, files, voice,
 * and video.
 *
 * A DELIBERATE, SETTLED divergence from the mockup's @handle people-picker: identities are
 * pseudonymous and there is NO user directory to pick from, so you cannot add people by handle here.
 * You start the conversation, then bring people in with an invite LINK (the "Bring people in" step
 * on the inbox). This is the same, honest arrival mechanism every private room uses. There is also
 * no persisted DM-vs-group kind — one field (a name) creates the room either way.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import Card from '@/Components/Ui/Card.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import Icon from '@/Components/Ui/Icon.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

// One field creates the room; store() lands back on the inbox with the invite-link share step open.
const form = useForm({ name: '' });
function start() {
    form.post('/civic/rooms');
}
</script>

<template>
    <Head :title="t('places.message_new.title', 'New message')" />

    <div class="stack" style="gap: var(--space-4)">
        <header>
            <p class="eyebrow">{{ t('places.message_new.eyebrow', 'People, together · start a conversation') }}</p>
            <h1>{{ t('places.message_new.title', 'New message') }}</h1>
            <p class="page-intro">
                {{ t('places.message_new.intro', 'Message one person directly, or start a group with a few. Either way it is just a conversation — talk, files, voice, and video.') }}
            </p>
        </header>

        <Card as="section">
            <form class="stack" style="gap: var(--space-4)" @submit.prevent="start">
                <Field
                    :label="t('places.message_new.name_label', 'Name this conversation')"
                    :hint="t('places.message_new.name_hint', 'A friendly label so you can find it later — direct or group, one field starts it.')"
                    :error="form.errors.name"
                >
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="form.name"
                            type="text"
                            class="field-input"
                            style="inline-size: 100%"
                            maxlength="200"
                            :placeholder="t('places.message_new.name_placeholder', 'e.g. Saturday crew — or a friend’s name for a direct message')"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>

                <div class="cluster" style="gap: var(--space-3)">
                    <Btn type="submit" variant="primary" :disabled="form.processing || !form.name.trim()">
                        <Icon name="message-square" size="sm" /> {{ t('places.message_new.submit', 'Start the conversation') }}
                    </Btn>
                    <Link href="/civic/rooms" class="btn btn--ghost">{{ t('places.message_new.cancel', 'Cancel') }}</Link>
                </div>
            </form>
        </Card>

        <div class="lr-note">
            <div><Icon name="user" size="sm" /></div>
            <div>
                <strong style="color: var(--gov-fg)">{{ t('places.message_new.note_link_title', 'You add people with a link, not by searching for them.') }}</strong>
                {{ t('places.message_new.note_link_body', 'Names here are pseudonymous and there is no directory to look anyone up in — so once the conversation exists, you share a private invite link and whoever opens it lands inside. Nobody can be added without a link they chose to open.') }}
            </div>
        </div>

        <div class="lr-note">
            <div><Icon name="shield" size="sm" /></div>
            <div>
                <strong style="color: var(--gov-fg)">{{ t('places.message_new.note_temp_title', 'A group message is temporary and private.') }}</strong>
                {{ t('places.message_new.note_temp_body', 'It grants no governance power, and it is private like a ballot — only the people in it can read it. When everyone leaves, it is gone. If a group wants to last, it can become an') }}
                <Link href="/organizations" class="underline">{{ t('places.message_new.organization', 'organization') }}</Link>{{ t('places.message_new.note_temp_tail', ' — but it never has to.') }}
            </div>
        </div>
    </div>
</template>
