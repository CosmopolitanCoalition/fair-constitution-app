<script setup>import { useLocaleFormat } from '@/composables/useLocaleFormat';
const localeFmt = useLocaleFormat();
/**
 * Social/Achievements — the full catalog over the sealed append-only
 * ledger (contract mockups/v3/social/achievements.html; K-2).
 *
 * The constitutional fence, rendered plainly: achievements DECORATE, they
 * never EMPOWER (CI-1). And this page never counts — no total, no
 * percentage, no "N of M", no progress ring (PI-6: a completion figure is
 * a per-person composite score wearing a different hat). Unearned entries
 * read "not yet earned" and nothing else (PI-2 — absence never names a
 * reason).
 *
 * Three scopes, three meanings: PERSONAL entries mark a person's own
 * civic firsts; JURISDICTION milestones celebrate a place (they carry no
 * user, ever — what makes a place leaderboard lawful while an individual
 * one never is, PI-1); SYSTEM milestones mark the whole instance.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import StatusBadge from '@/Components/Ui/StatusBadge.vue';
import { achievementTitle } from '@/lib/achievementTitle';

defineOptions({ layout: AppShellV2 });

const props = defineProps({
    surface: { type: Object, required: true },
    signedIn: { type: Boolean, default: false },
    catalog: {
        type: Object,
        default: () => ({ personal: [], jurisdiction: [], system: [] }),
    },
    earned: { type: Object, default: () => ({}) },
});

const { t } = useI18n({ useScope: 'global' });

const fmtDate = (iso) => (iso ? localeFmt.date(new Date(iso)) : '');

const earnedAt = (key) => props.earned[key]?.earned_at ?? null;

/* Personal entries split by tier: verified (server-proven) vs the guided
   journey arcs (self-reported walkthroughs — labeled so they can never
   borrow the verified tier's credibility). */
const verified = computed(() => props.catalog.personal.filter((e) => !e.is_arc));
const arcs = computed(() => props.catalog.personal.filter((e) => e.is_arc));

const title = (entry) => achievementTitle(entry.title_key, t);
</script>

<template>
    <PageScaffold :surface="surface" :title="t('c_gap_civic_social.achievements.title', 'Achievements')">
        <template #intro>
            {{ t('c_gap_civic_social.achievements.intro', 'Your earned records — civic firsts and finished journeys — kept as a list, never a score. Nothing on this page grants anything: no vote, no seat, no role, no eligibility, ever.') }}
        </template>

        <Banner tone="info">
            <strong>{{ t('c_gap_civic_social.achievements.fence_lead', 'The one fence, plainly:') }}</strong> {{ t('c_gap_civic_social.achievements.fence_body', 'achievements decorate — they never empower. No gate anywhere consults this page: standing for office needs residency, and nothing else (Art. I · CI-1). And the service that writes it refuses to count: it returns which achievements you hold, never how many (PI-6).') }}
        </Banner>

        <Banner v-if="!signedIn" tone="info">
            {{ t('c_gap_civic_social.achievements.browsing_public', 'You are browsing the public catalog.') }} <Link href="/login" class="prose-link">{{ t('c_gap_civic_social.achievements.sign_in', 'Sign in') }}</Link> {{ t('c_gap_civic_social.achievements.signin_tail', 'to see your own earned marks — they are yours alone until you choose to show them on your profile.') }}
        </Banner>

        <!-- ─────────────────────────────────────── personal · verified -->
        <Card as="section" :title="t('c_gap_civic_social.achievements.verified_title', 'Civic firsts — verified')">
            <p class="gloss">
                {{ t('c_gap_civic_social.achievements.verified_gloss', 'Written by the system when the permanent record shows the act — a filing, a seating, a confirmation. Entries about voting read only the sealed envelope that proves you took part; the ballot inside is never opened (Art. II).') }}
            </p>
            <div class="role-grid">
                <div
                    v-for="entry in verified"
                    :key="entry.key"
                    class="role-card"
                    :class="{ 'role-card--unearned': !earnedAt(entry.key) }"
                >
                    <Icon name="award" />
                    <div>
                        <strong>{{ title(entry) }}</strong>
                        <p class="citation" style="margin: 0">
                            <template v-if="earnedAt(entry.key)">{{ t('c_gap_civic_social.achievements.earned', { date: fmtDate(earnedAt(entry.key)) }) }}</template>
                            <template v-else-if="entry.awaiting_ui">{{ t('c_gap_civic_social.achievements.not_earnable', 'not yet earnable — its surface is still being built') }}</template>
                            <template v-else>{{ t('c_gap_civic_social.achievements.not_earned', 'not yet earned') }}</template>
                        </p>
                        <p class="citation" style="margin: 0">
                            <StatusBadge tone="success">{{ t('c_gap_civic_social.achievements.verified_badge', 'verified') }}</StatusBadge>
                            {{ t('c_gap_civic_social.achievements.proof', 'proof:') }} <code>{{ entry.trigger }}</code>
                        </p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- ─────────────────────────────────── personal · journey arcs -->
        <Card as="section" :title="t('c_gap_civic_social.achievements.journeys_title', 'Guided journeys — walkthroughs')">
            <p class="gloss">
                {{ t('c_gap_civic_social.achievements.journeys_gloss', 'Finished journey arcs are self-reported ticks — labeled walkthroughs precisely so they cannot borrow the verified tier\'s credibility.') }}
            </p>
            <div class="role-grid">
                <div
                    v-for="entry in arcs"
                    :key="entry.key"
                    class="role-card"
                    :class="{ 'role-card--unearned': !earnedAt(entry.key) }"
                >
                    <Icon name="list-checks" />
                    <div>
                        <strong>{{ title(entry) }}</strong>
                        <p class="citation" style="margin: 0">
                            <template v-if="earnedAt(entry.key)">{{ t('c_gap_civic_social.achievements.earned', { date: fmtDate(earnedAt(entry.key)) }) }}</template>
                            <template v-else>{{ t('c_gap_civic_social.achievements.not_earned', 'not yet earned') }}</template>
                            · <StatusBadge tone="neutral">{{ t('c_gap_civic_social.achievements.walkthrough_badge', 'walkthrough') }}</StatusBadge>
                        </p>
                        <Link :href="`/journeys/${entry.key}`" class="citation">
                            {{ earnedAt(entry.key) ? t('c_gap_civic_social.achievements.revisit_journey', 'Revisit the journey') : t('c_gap_civic_social.achievements.start_journey', 'Start the journey') }}
                        </Link>
                    </div>
                </div>
            </div>
        </Card>

        <!-- ──────────────────────────────────── jurisdiction milestones -->
        <Card as="section" :title="t('c_gap_civic_social.achievements.jurisdiction_title', 'Jurisdiction milestones — a place, never a person')">
            <p class="gloss">
                {{ t('c_gap_civic_social.achievements.jurisdiction_gloss', 'These celebrate what a place does together — a first election, a seated court, a mutual-aid net. They carry no person\'s name, ever: jurisdictions may be celebrated side by side; people are never ranked against each other (PI-1).') }}
            </p>
            <div class="role-grid">
                <div v-for="entry in catalog.jurisdiction" :key="entry.key" class="role-card role-card--unearned">
                    <Icon name="landmark" />
                    <div>
                        <strong>{{ title(entry) }}</strong>
                        <p class="citation" style="margin: 0">{{ t('c_gap_civic_social.achievements.not_reached_published', 'not yet reached · published to the public record when it is') }}</p>
                    </div>
                </div>
            </div>
        </Card>

        <!-- ─────────────────────────────────────────── system milestones -->
        <Card as="section" :title="t('c_gap_civic_social.achievements.system_title', 'System milestones — the whole instance')">
            <div class="role-grid">
                <div v-for="entry in catalog.system" :key="entry.key" class="role-card role-card--unearned">
                    <Icon name="globe" />
                    <div>
                        <strong>{{ title(entry) }}</strong>
                        <p class="citation" style="margin: 0">{{ t('c_gap_civic_social.achievements.not_reached', 'not yet reached') }}</p>
                    </div>
                </div>
            </div>
            <p class="citation" style="margin-block-start: var(--space-2)">
                {{ t('c_gap_civic_social.achievements.ledger_note', 'The ledger behind this page can only ever gain an entry — append-only by database trigger, sealed to the audit chain. An achievement is earned once; the one-time training completion bonus that reads it can never pay twice.') }}
            </p>
        </Card>

        <p style="margin-block-start: var(--space-3)">
            <Btn :as="Link" href="/journeys" variant="secondary" size="sm">{{ t('c_gap_civic_social.achievements.the_guided_journeys', 'The guided journeys') }}</Btn>
            <Btn v-if="signedIn" :as="Link" href="/civic/record?tab=achievements" variant="ghost" size="sm">
                {{ t('c_gap_civic_social.achievements.your_earned_list', 'Your earned list on My record') }}
            </Btn>
        </p>
    </PageScaffold>
</template>
