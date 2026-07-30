<script setup>
/**
 * Legislature/BillConversation — "A bill — the conversation" (mockups/v3/shared/bill.html). The
 * conversation face of a bill: what it says, where it is on its way to law, HOW its words change
 * (amendments the chamber VOTES on — never a per-party edit, Art. V §3), and the public comments that
 * ride the bill's bound hall subforum. The formal record (versions + the vote math) is one link away.
 *
 * There is no per-clause redline editor by design (that is the Art. V §3 violation the mockup implied
 * and the engine rejects). There is no fabricated summary — the real current text is shown, or an
 * honest empty state when a version carries none.
 */
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import Card from '@/Components/Ui/Card.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';
import Icon from '@/Components/Ui/Icon.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });

const props = defineProps({
    bill: { type: Object, required: true },
    stages: { type: Object, default: () => ({ path: [], terminal: null }) },
    comments: { type: Array, default: () => [] },
    commentState: { type: String, default: 'no_space' }, // 'open' | 'needs_auth' | 'no_space'
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);

const STAGE_ICON = { done: 'check', current: 'clock', pending: 'minus' };

const comment = useForm({ body: '' });
function postComment() {
    comment.post(`/bills/${props.bill.id}/comments`, { preserveScroll: true, onSuccess: () => comment.reset('body') });
}
</script>

<template>
    <Head :title="bill.title" />

    <div class="stack" style="gap: var(--space-4)">
        <header>
            <p class="eyebrow">Lawmaking · the conversation on a bill</p>
            <h1>{{ bill.title }}</h1>
            <p class="gloss">
                <Icon name="user" size="sm" /> Sponsored by {{ bill.sponsor }}
                <template v-if="bill.jurisdiction"> · <Icon name="landmark" size="sm" /> {{ bill.jurisdiction }}</template>
            </p>
            <p class="page-intro">
                This is where people talk the bill over and work its meaning; the formal record keeps the
                lifecycle and the vote math.
            </p>
        </header>

        <p v-if="flashStatus" class="gloss" role="status" style="color: var(--status-success-fg)">{{ flashStatus }}</p>

        <!-- Progress -->
        <Card as="section">
            <h2 style="margin: 0"><Icon name="list-checks" size="sm" /> Progress</h2>
            <p class="gloss">Where this bill is on its way to becoming law.</p>
            <ol class="bill-stages">
                <li v-for="(st, i) in stages.path" :key="i" class="bill-stage" :class="`bill-stage--${st.state}`">
                    <span class="bill-stage-dot"><Icon :name="STAGE_ICON[st.state] || 'minus'" size="sm" /></span>
                    <span class="bill-stage-label">{{ st.label }}</span>
                </li>
            </ol>
            <p v-if="stages.terminal" class="gloss" style="margin-block-start: var(--space-2)">
                This bill ended: <strong>{{ stages.terminal.label }}</strong>.
            </p>
        </Card>

        <!-- The text — bills carry no summary field, so we show the real words (honest-empty when none) -->
        <Card as="section">
            <h2 style="margin: 0"><Icon name="file-text" size="sm" /> The text</h2>
            <p class="gloss">
                Version {{ bill.versionCount }}<template v-if="bill.versionCount > 1"> — the current text after
                {{ bill.versionCount - 1 }} amendment{{ bill.versionCount - 1 === 1 ? '' : 's' }}</template>.
            </p>
            <pre v-if="bill.text" class="bill-text">{{ bill.text }}</pre>
            <p v-else class="gloss">This version carries no text yet.</p>
        </Card>

        <!-- How the words change — the constitutional amendment path (NOT a per-party redline) -->
        <Card as="section" inset>
            <h2 style="margin: 0"><Icon name="scale" size="sm" /> How the words change</h2>
            <p style="margin-block-start: var(--space-2)">
                A bill's text changes only through <strong>amendments the chamber votes on</strong> — first in
                committee, then on the floor. No single person or group edits it directly; each amendment the
                chamber adopts becomes a new version, and both chambers must independently agree for it to pass
                (Art. V §3).
            </p>
            <p style="margin-block-start: var(--space-2)">
                <Link :href="bill.formalHref" class="btn btn--secondary btn--sm">
                    <Icon name="list-checks" size="sm" /> See the versions &amp; the vote math
                </Link>
            </p>
        </Card>

        <!-- Comments — riding the bill's bound hall subforum -->
        <Card as="section">
            <h2 style="margin: 0"><Icon name="message-square" size="sm" /> Comments</h2>
            <p class="gloss">
                Residents and members weigh in. Comments are public talk on the bill — separate from the formal
                floor record, and posted on the public record of the halls.
            </p>

            <div v-if="comments.length" class="neg-comments" style="margin-block-start: var(--space-2)">
                <div v-for="c in comments" :key="c.id" class="neg-comment">
                    <span class="neg-comment-who">{{ c.author_display }}</span>
                    <span class="neg-comment-text">{{ c.body }}</span>
                    <span class="neg-comment-when">{{ c.at }}</span>
                </div>
            </div>
            <p v-else class="gloss" style="margin-block-start: var(--space-2)">No comments yet — be the first to weigh in.</p>

            <form
                v-if="commentState === 'open'"
                class="stack"
                style="gap: var(--space-2); margin-block-start: var(--space-3)"
                @submit.prevent="postComment"
            >
                <Field label="Add a comment" :error="comment.errors.body">
                    <template #control="{ id, invalid, describedBy }">
                        <input
                            :id="id"
                            v-model="comment.body"
                            type="text"
                            class="field-input"
                            style="inline-size: 100%"
                            maxlength="20000"
                            placeholder="Add a comment…"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>
                <div>
                    <Btn type="submit" variant="secondary" size="sm" :disabled="comment.processing || !comment.body.trim()">
                        <Icon name="arrow-right" size="sm" /> Comment
                    </Btn>
                </div>
            </form>
            <p v-else-if="commentState === 'needs_auth'" class="gloss" style="margin-block-start: var(--space-3)">
                <Link href="/login" class="underline">Sign in</Link> to add a comment.
            </p>
            <p v-else class="gloss" style="margin-block-start: var(--space-3)">
                The discussion opens once this bill is live in the halls.
            </p>
        </Card>

        <!-- Footer -->
        <div class="cluster" style="gap: var(--space-2)">
            <Link v-if="bill.chamberHref" :href="bill.chamberHref" class="btn btn--secondary btn--sm">
                <Icon name="users" size="sm" /> Watch the floor session <Icon name="arrow-right" size="sm" />
            </Link>
            <Link :href="bill.formalHref" class="btn btn--ghost btn--sm">The formal record — lifecycle &amp; the vote math</Link>
        </div>
    </div>
</template>

<style scoped>
.bill-text {
    white-space: pre-wrap;
    font-family: var(--font-mono);
    font-size: var(--text-sm);
    background: var(--gov-surface-2);
    border: 1px solid var(--gov-border);
    border-radius: var(--radius-md);
    padding: var(--space-3);
    margin-block-start: var(--space-2);
    overflow-x: auto;
}
</style>
