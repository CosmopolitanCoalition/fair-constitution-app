<script setup>
/** Public discussion for the same selected bill as the formal record. */
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import BillWorkspaceNav from '@/Components/Legislature/BillWorkspaceNav.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Card from '@/Components/Ui/Card.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Field from '@/Components/Ui/Field.vue';

defineOptions({ layout: AppShellV2 });
const props = defineProps({
    surface: { type: Object, required: true },
    workspace: { type: Object, required: true },
    comments: { type: Array, default: () => [] },
    commentPages: { type: Object, default: null },
    commentState: { type: String, default: 'no_space' },
});
const { t } = useI18n();
const text = (key) => t('c_bill.' + key);
const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);
const comment = useForm({ body: '' });
function postComment() {
    comment.post(`/bills/${props.workspace.id}/comments`, {
        preserveScroll: true,
        onSuccess: () => comment.reset('body'),
    });
}
</script>

<template>
    <PageScaffold :surface="surface" :title="workspace.title">
        <BillWorkspaceNav :workspace="workspace" active="discussion" />
        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency" :title="text('rejected')">{{ constitutionError }}</Banner>

        <Card as="section" :title="text('discussion')">
            <p class="gloss">{{ text('discussion_intro') }}</p>
            <form v-if="commentState === 'open'" class="stack bill-comment-form" @submit.prevent="postComment">
                <Field :label="text('add_comment')" :error="comment.errors.body">
                    <template #control="{ id, invalid, describedBy }">
                        <textarea
                            :id="id"
                            v-model="comment.body"
                            class="field-input"
                            rows="4"
                            maxlength="20000"
                            required
                            :placeholder="text('comment_placeholder')"
                            :aria-invalid="invalid ? 'true' : undefined"
                            :aria-describedby="describedBy"
                        />
                    </template>
                </Field>
                <div>
                    <Btn type="submit" variant="primary" size="sm" :disabled="comment.processing || !comment.body.trim()">
                        {{ text('post_comment') }}
                    </Btn>
                </div>
            </form>
            <p v-else-if="commentState === 'needs_auth'" class="bill-comment-form">
                <Link href="/login">{{ text('sign_in') }}</Link>
            </p>
            <p v-else class="gloss bill-comment-form">{{ text('no_space') }}</p>

            <h3 class="bill-comment-heading">{{ text('comments') }}</h3>
            <ol v-if="comments.length" class="bill-comments">
                <li v-for="entry in comments" :key="entry.id">
                    <p class="bill-comment-meta"><strong>{{ entry.author_display }}</strong><span>{{ entry.at }}</span></p>
                    <p class="bill-comment-body" data-no-i18n>{{ entry.body }}</p>
                </li>
            </ol>
            <p v-else class="gloss">{{ text('no_comments') }}</p>
            <nav v-if="commentPages?.newerHref || commentPages?.olderHref" class="bill-comment-pages" :aria-label="text('comment_pages')">
                <Link v-if="commentPages.olderHref" :href="commentPages.olderHref">{{ text('older_comments') }}</Link>
                <Link v-if="commentPages.newerHref" :href="commentPages.newerHref">{{ text('newer_comments') }}</Link>
            </nav>
        </Card>
    </PageScaffold>
</template>

<style scoped>
.bill-comment-form { margin-block-start: var(--space-3); }
.bill-comment-form textarea { inline-size: 100%; resize: vertical; }
.bill-comment-heading { margin-block: var(--space-4) var(--space-2); }
.bill-comments { list-style: none; padding: 0; margin: 0; }
.bill-comments li { padding-block: var(--space-3); border-block-start: 1px solid var(--gov-border); }
.bill-comment-meta { display: flex; flex-wrap: wrap; gap: .5rem 1rem; font-size: var(--text-sm); }
.bill-comment-meta span { color: var(--gov-fg-muted); }
.bill-comment-body { white-space: pre-wrap; overflow-wrap: anywhere; margin-block-start: .5rem; }
.bill-comment-pages { display: flex; flex-wrap: wrap; gap: 1rem; margin-block-start: var(--space-3); }
.bill-comment-pages a { display: inline-flex; align-items: center; min-height: 2.75rem; }
</style>
