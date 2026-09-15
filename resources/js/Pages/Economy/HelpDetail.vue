<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import WorkTradeNav from '@/Components/Economy/WorkTradeNav.vue';
import Card from '@/Components/Ui/Card.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Field from '@/Components/Ui/Field.vue';
import Btn from '@/Components/Ui/Btn.vue';
import { formatWhen } from '@/lib/money.js';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    surface: { type: Object, default: null },
    assistance: { type: Object, required: true },
    isOwner: { type: Boolean, default: false },
    canParticipate: { type: Boolean, default: false },
    participationNotice: { type: String, default: null },
    canPublish: { type: Boolean, default: false },
    canWithdraw: { type: Boolean, default: false },
    canResolve: { type: Boolean, default: false },
    canRespond: { type: Boolean, default: false },
    responses: { type: Object, default: () => ({ data: [], previous: null, next: null }) },
});
const page = usePage();
const reply = useForm(`help-response:${page.props.auth?.user?.id ?? 'session'}:${props.assistance.id}`, { message: '' });
const action = useForm({});
const busy = computed(() => action.processing || reply.processing);
const errors = computed(() => [...new Set(Object.values(page.props.errors ?? {}))]);
const status = computed(() => ({ open: 'Open for help', matched: 'Helper selected', resolved: 'Completed', withdrawn: 'Withdrawn', closed: 'Closed' }[props.assistance.status] ?? 'Recorded'));
const responseStatus = value => ({ offered: 'Offer of help', pending: 'Offer of help', accepted: 'Selected helper', selected: 'Selected helper', withdrawn: 'Offer withdrawn', declined: 'Not selected' }[value] ?? 'Response recorded');
function change(kind, response = null) {
    if (busy.value) return;
    const base = `/economy/help/${props.assistance.id}`;
    action.post(response ? `${base}/responses/${response.id}/${kind}` : `${base}/${kind}`, { preserveScroll: true });
}
function respond() {
    if (busy.value || !props.canRespond) return;
    reply.post(`/economy/help/${props.assistance.id}/responses`, { preserveScroll: true, onSuccess: () => reply.reset() });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="assistance.title">
        <template #intro>{{ status }}</template>
        <WorkTradeNav active="help" :back-href="isOwner ? '/economy/help?tab=mine' : '/economy/help'" :back-label="isOwner ? 'My requests' : 'Requests for help'" />
        <Banner v-if="page.props.flash?.status" tone="info" role="status">{{ page.props.flash.status }}</Banner>
        <Banner v-if="errors.length" tone="emergency" role="alert"><p v-for="error in errors" :key="error">{{ error }}</p></Banner>
        <p v-if="busy" role="status">Saving…</p>
        <Banner v-if="participationNotice" tone="info">{{ participationNotice }} <Link href="/economy/wallet" class="prose-link">Open my wallet</Link></Banner>
        <Card as="section" title="The request">
            <p class="help-note">{{ assistance.privacy === 'public' ? 'Public request' : assistance.privacy === 'private' ? 'Private request' : 'Restricted request' }}<template v-if="assistance.created_at"> · {{ formatWhen(assistance.created_at) }}</template></p>
            <p class="help-body">{{ assistance.need }}</p>
            <p v-if="canPublish" class="help-note">This draft is visible only to you. Publishing makes its title and description visible in the public market so people can offer help.</p>
            <p v-if="canResolve" class="help-note">Mark completed when the help has been provided. This records the outcome; it does not transfer money or sign an agreement.</p>
            <div class="help-links">
                <Btn v-if="canPublish" :disabled="busy" @click="change('publish')">Publish request publicly</Btn>
                <Btn v-if="canResolve" :disabled="busy" @click="change('resolve')">Mark completed</Btn>
                <Btn v-if="canWithdraw" variant="secondary" :disabled="busy" @click="change('withdraw')">Withdraw request</Btn>
            </div>
        </Card>
        <Card v-if="canRespond" as="section" title="Offer to help">
            <form @submit.prevent="respond">
                <Field label="Your offer of help" required :error="reply.errors.message" hint="Describe what you can do and when. Only you and the requester can read this reply.">
                    <template #control="{ id, describedBy, invalid }"><textarea :id="id" v-model="reply.message" rows="4" maxlength="5000" required :aria-describedby="describedBy" :aria-invalid="invalid" /></template>
                </Field>
                <Btn type="submit" :disabled="busy">{{ reply.processing ? 'Sending…' : 'Send offer of help' }}</Btn>
            </form>
        </Card>
        <section aria-labelledby="help-responses-title">
            <h2 id="help-responses-title">{{ isOwner ? 'Offers of help' : 'My response' }}</h2>
            <p v-if="!responses.data.length">{{ isOwner ? 'No responses on this page.' : 'You have no response on this page.' }}</p>
            <Card v-for="response in responses.data" :key="response.id" as="article" class="help-card">
                <h3>{{ responseStatus(response.status) }}</h3>
                <p v-if="response.created_at" class="help-note">{{ formatWhen(response.created_at) }}</p>
                <p class="help-body">{{ response.message }}</p>
                <p v-if="response.canAccept" class="help-note">Selecting this offer closes the request to other helpers until it is completed or the selected helper withdraws.</p>
                <div class="help-links">
                    <Btn v-if="response.canAccept" :disabled="busy" @click="change('accept', response)">Choose this helper</Btn>
                    <Btn v-if="response.canWithdraw" variant="secondary" :disabled="busy" @click="change('withdraw', response)">Withdraw my offer</Btn>
                </div>
            </Card>
            <nav class="help-links" aria-label="Response pages">
                <Link v-if="responses.previous" :href="responses.previous" rel="prev">Previous responses</Link>
                <Link v-if="responses.next" :href="responses.next" rel="next">Next responses</Link>
            </nav>
        </section>
    </PageScaffold>
</template>

<style scoped>
.help-body { white-space: pre-wrap; overflow-wrap: anywhere; }
.help-note { color: var(--gov-text-muted); font-size: .875rem; }
.help-links { display: flex; flex-wrap: wrap; gap: .5rem 1rem; margin-block: .75rem; }
.help-links a { display: inline-flex; align-items: center; min-block-size: 44px; padding: .4rem .6rem; }
.help-card { margin-block: 1rem; }
a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
</style>
