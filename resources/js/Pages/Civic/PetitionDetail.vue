<script setup>
/**
 * Civic/PetitionDetail — FE-C10 (PHASE_C_DESIGN_frontend.md §B.13).
 *
 * LifecycleTracker through the 9-state machine · law text blockquote ·
 * full SignatureMeter + sign toggle ("signatures stay open during review;
 * the audited count is frozen at the threshold check") · F-ELB-005 audit
 * card rendered as a read-only record with the result grammar · the
 * HONEST F-JDG-008 review stub ("awaiting judiciary · Planned · Phase E —
 * the kill-path is constitutional, not skippable") · on-ballot card.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import AdmChip from '@/Components/Ui/AdmChip.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import LifecycleTracker from '@/Components/Ui/LifecycleTracker.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import SignatureMeter from '@/Components/Civic/SignatureMeter.vue';

/* Phase-2 restyle wave: the v3 player chrome (MASTER_PLAN). */
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();

const props = defineProps({
    surface: { type: Object, required: true },
    petition: { type: Object, required: true },
    machine: { type: Array, default: () => [] },
    currentState: { type: String, required: true },
    audit: { type: Object, default: null },
    review: { type: Object, default: null },
    ballot: { type: Object, default: null },
    urls: { type: Object, required: true },
});

const page = usePage();
const flashStatus = computed(() => page.props.flash?.status ?? null);
const constitutionError = computed(() => page.props.errors?.constitution ?? null);

/* Happy path for the tracker; the live branch splices in when terminal. */
const BRANCHES = ['rejected', 'invalidated'];
const trackerStages = computed(() => {
    const happy = props.machine.filter((s) => !BRANCHES.includes(s));
    return BRANCHES.includes(props.currentState) ? [...happy, props.currentState] : happy;
});

/* --------------------------------------------- sign/revoke (F-IND-010) -- */
const signing = ref(false);
function toggleSignature() {
    signing.value = true;
    const options = {
        preserveScroll: true,
        onFinish: () => {
            signing.value = false;
        },
    };
    if (props.petition.signed_by_me) {
        router.delete(props.urls.signatures, { data: { form_id: 'F-IND-010' }, ...options });
    } else {
        router.post(props.urls.signatures, { form_id: 'F-IND-010' }, options);
    }
}
</script>

<template>
    <PageScaffold :surface="surface" :title="petition.title">
        <template #intro>
            {{ t('c_civic.petition_detail.intro', { name: petition.jurisdiction.name, creator: petition.creator ?? t('c_civic.petition_detail.an_associated_resident', 'an associated resident') }) }}
        </template>

        <Banner v-if="flashStatus" tone="info" role="status">{{ flashStatus }}</Banner>
        <Banner v-if="constitutionError" tone="emergency">{{ constitutionError }}</Banner>

        <!-- ==================================== lifecycle ================ -->
        <Card as="section" :title="t('c_civic.petition_detail.lifecycle', 'Lifecycle')">
            <LifecycleTracker :stages="trackerStages" :current="currentState" />
            <p class="citation" style="margin-block-start: var(--space-2)">
                {{ t('c_civic.petition_detail.lifecycle_cite', 'Created → … → Constitutional-Review → Validated → On-Ballot · Art. II §6') }}
            </p>
        </Card>

        <!-- ==================================== law text ================= -->
        <Card as="section" :title="t('c_civic.petition_detail.law_text_title', 'Proposed law text')">
            <blockquote
                style="margin: 0; border-inline-start: 3px solid var(--gov-border-strong); padding-inline-start: var(--space-4); color: var(--gov-fg-strong); font-family: var(--font-mono, monospace); white-space: pre-wrap"
            >{{ petition.law_text }}</blockquote>
            <p class="gloss" style="margin-block-start: var(--space-3)">
                {{ t('c_civic.petition_detail.law_text_note', 'This is the binding text voters ratify — not a summary.') }}
            </p>
            <div class="grid-2" style="margin-block-start: var(--space-4)">
                <div>
                    <h3>{{ t('c_civic.petition_detail.scale', 'Scale') }}</h3>
                    <p>
                        <AdmChip :level="petition.jurisdiction.adm_level ?? 0" :label="petition.scale.join(' · ') || petition.jurisdiction.name" />
                    </p>
                    <p class="citation">{{ t('c_civic.petition_detail.adopted_scale', { type: petition.act_type }) }}</p>
                </div>
                <div>
                    <h3>{{ t('c_civic.petition_detail.scope', 'Scope') }}</h3>
                    <p class="cc-small">{{ petition.scope_label }}</p>
                    <p class="citation">{{ t('c_civic.petition_detail.scope_cite', 'scale & scope travel with the law · Art. II §6; Art. V §4') }}</p>
                </div>
            </div>
        </Card>

        <!-- ==================================== signatures =============== -->
        <Card as="section" :title="t('c_civic.petition_detail.signatures', 'Signatures')">
            <SignatureMeter
                :signatures="petition.signatures"
                :threshold="petition.threshold_count"
                :pct="petition.pct"
            />
            <p class="citation" style="margin-block-start: var(--space-1)">
                {{ t('c_civic.petition_detail.threshold_line', { count: petition.threshold_count.toLocaleString(), pct: petition.pct, basis: petition.population_basis.toLocaleString() }) }}
            </p>
            <div class="cluster" style="margin-block-start: var(--space-3)">
                <Btn
                    :variant="petition.signed_by_me ? 'secondary' : 'primary'"
                    :aria-pressed="petition.signed_by_me ? 'true' : 'false'"
                    :disabled="!petition.signable || signing"
                    :title="petition.signable ? undefined : t('c_civic.petition_detail.frozen_title', 'audited count frozen at the threshold check')"
                    @click="toggleSignature"
                >{{ petition.signed_by_me ? t('c_civic.petition_detail.signed', 'Signed') : t('c_civic.petition_detail.sign_this', 'Sign this petition') }}</Btn>
                <StatusBadge v-if="petition.signed_by_me" tone="success" icon="check">
                    {{ t('c_civic.petition_detail.appended', 'Your signature is appended to the record') }}
                </StatusBadge>
            </div>
            <p class="cc-small" style="margin-block-start: var(--space-2)">
                {{ t('c_civic.petition_detail.sig_form', 'Petition signature') }} <span class="form-chip"><span class="form-id" data-no-i18n>F-IND-010</span></span> {{ t('c_civic.petition_detail.sig_form_after', '— signatures stay open during review; the audited count is frozen at the threshold check.') }}
                <span class="citation" style="display: block">{{ t('c_civic.petition_detail.sig_cite', 'available to R-03 Jurisdictionally Associated · Art. II §6') }}</span>
            </p>
        </Card>

        <div class="grid-2">
            <!-- ================================== audit (F-ELB-005) ====== -->
            <section class="card" aria-labelledby="audit-h">
                <h2 id="audit-h">{{ t('c_civic.petition_detail.audit_h', 'Signature audit') }} <span class="form-chip"><span class="form-id" data-no-i18n>F-ELB-005</span></span></h2>
                <template v-if="audit">
                    <p style="margin-block-start: var(--space-2)">
                        <StatusBadge :tone="audit.result.still_above ? 'success' : 'danger'" :icon="audit.result.still_above ? 'check' : 'x'">
                            {{ audit.result.still_above ? t('c_civic.petition_detail.audit_confirmed', 'Audit complete — threshold confirmed') : t('c_civic.petition_detail.audit_invalidated', 'Audit complete — below threshold, petition invalidated') }}
                        </StatusBadge>
                    </p>
                    <p class="cc-small" style="margin-block-start: var(--space-2)">
                        {{ t('c_civic.petition_detail.audit_counts', { valid: audit.result.valid.toLocaleString(), checked: audit.result.checked.toLocaleString(), pct: audit.result.pct_valid }) }}
                        {{ audit.result.still_above
                            ? t('c_civic.petition_detail.audit_still_above', { threshold: petition.threshold_count.toLocaleString() })
                            : t('c_civic.petition_detail.audit_below', { threshold: petition.threshold_count.toLocaleString() }) }}
                        <template v-if="audit.completed_at"> {{ t('c_civic.petition_detail.audit_completed', { when: audit.completed_at, board: audit.board_name }) }}</template>
                    </p>
                    <p class="citation">
                        {{ t('c_civic.petition_detail.audit_cite', 'independent audit · an R-08 action surfaced read-only here · Art. II §6 · CLK-17') }}
                        <template v-if="audit.record_href"> · <a :href="audit.record_href">{{ t('c_civic.petition_detail.sealed_record', 'sealed record →') }}</a></template>
                    </p>
                </template>
                <template v-else>
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        {{ t('c_civic.petition_detail.audit_empty', 'The election board\'s independent audit runs when the threshold is reached — every unrevoked signature is verified against point-in-time association. Kill-path: too many invalid → invalidated.') }}
                    </p>
                    <p class="citation">{{ t('c_civic.petition_detail.audit_cite_empty', 'independent audit · Art. II §6 · CLK-17') }}</p>
                </template>
            </section>

            <!-- ================================== review (F-JDG-008) ===== -->
            <section class="card" aria-labelledby="review-h">
                <h2 id="review-h">{{ t('c_civic.petition_detail.review_h', 'Constitutionality review') }} <span class="form-chip"><span class="form-id" data-no-i18n>F-JDG-008</span></span></h2>
                <template v-if="review">
                    <p style="margin-block-start: var(--space-2)">
                        <StatusBadge
                            :tone="review.status === 'pending' ? 'warning' : review.status === 'validated' ? 'success' : 'danger'"
                            icon="scale"
                        >
                            {{ review.status === 'pending' ? t('c_civic.petition_detail.awaiting_judiciary', { court: review.court_label }) : review.status }}
                        </StatusBadge>
                    </p>
                    <p v-if="review.stubbed && review.status === 'pending'" class="cc-small" style="margin-block-start: var(--space-2)">
                        {{ t('c_civic.petition_detail.review_stub_pending', 'Constitutional review · awaiting judiciary (Planned · Phase E). Petitions at this stage hold until review exists; the kill-path is constitutional, not skippable.') }}
                    </p>
                    <p v-else-if="review.stubbed" class="cc-small" style="margin-block-start: var(--space-2)">
                        {{ t('c_civic.petition_detail.review_stub_advanced', 'Advanced past the review hold by the audited Phase C stub — recorded on the chain with the deferral citation; the real F-JDG-008 referral lands with the judiciary (Phase E).') }}
                    </p>
                </template>
                <template v-else>
                    <p class="gloss" style="margin-block-start: var(--space-2)">
                        {{ t('c_civic.petition_detail.review_empty', 'Review follows a passed signature audit. If the proposed law unjustly impedes rights, the petition is invalidated; otherwise it is validated for the next jurisdiction-wide ballot.') }}
                    </p>
                </template>
                <p class="citation">{{ t('c_civic.petition_detail.review_cite', 'petitions invalidated if unconstitutional · Art. II §6 · WF-JUD-09') }}</p>
            </section>
        </div>

        <!-- ==================================== on ballot ================ -->
        <Card v-if="ballot" as="section" :title="t('c_civic.petition_detail.on_ballot_title', 'On the ballot')">
            <p class="cc-small">
                {{ t('c_civic.petition_detail.ballot_before', 'The question rides the') }}
                <Link :href="ballot.href">{{ ballot.label }}</Link>
                {{ t('c_civic.petition_detail.ballot_after', 'at the threshold matching the act type (WF-ELE-07) — absent voters count exactly like a no.') }}
            </p>
        </Card>

        <template #about>
            <p>
                <Link href="/civic/petitions">{{ t('c_civic.petition_detail.all_petitions', '← All petitions') }}</Link>
            </p>
        </template>
    </PageScaffold>
</template>
