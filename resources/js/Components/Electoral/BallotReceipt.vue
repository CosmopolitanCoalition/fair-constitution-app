<script setup>
/**
 * Electoral/BallotReceipt — the shown-once receipt hash (.receipt).
 * PHASE_B_DESIGN_frontend.md §A.5; ballot UX integrity §D: the hash crosses
 * the wire exactly once (session flash); it is never retrievable later, by
 * the voter or by anyone.
 *
 * `compact` is the referendum variant: a single citation line carrying the
 * first 17 grouped-hash characters (mockup ranked-ballot.html #ref-receipt).
 *
 * Deferred from the mockup (per the design's E-defers): the
 * .toast--achievement "First ballot committed" block — the gamification
 * layer is Proposed and frontend-port row 28 forbids building it until
 * approved.
 */
import { computed, ref } from 'vue';
import Banner from '@/Components/Ui/Banner.vue';
import Btn from '@/Components/Ui/Btn.vue';
import Icon from '@/Components/Ui/Icon.vue';
import { useAnnounce } from '@/composables/useAnnounce';

const props = defineProps({
    /** 64-hex; rendered in 8-char groups (mockup format). */
    hash: { type: String, required: true },
    copyable: { type: Boolean, default: true },
    /** 'Self-audit in the public count record →'. */
    resultsHref: { type: String, default: null },
    /** Referendum receipt: single citation line, first 17 chars. */
    compact: { type: Boolean, default: false },
});

const { announce } = useAnnounce();
const copied = ref(false);

const grouped = computed(() => props.hash.replace(/(.{8})/g, '$1 ').trim());
const groupedCompact = computed(() => grouped.value.slice(0, 17));

/* Clipboard copies the RAW 64-hex hash (no grouping spaces). */
async function copy() {
    try {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
            await navigator.clipboard.writeText(props.hash);
        } else {
            fallbackCopy(props.hash);
        }
        announce('Receipt copied');
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        fallbackCopy(props.hash);
        announce('Receipt copied');
    }
}

/* textarea-select fallback for non-secure contexts (http dev hosts). */
function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.insetBlockStart = '-100vh';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
}
</script>

<template>
    <p v-if="compact" class="citation" role="status" data-no-i18n>
        <slot>Receipt</slot> {{ groupedCompact }}
    </p>

    <div v-else class="stack" style="gap: var(--space-3)">
        <Banner tone="warning">
            This receipt is shown <strong>once</strong>. Copy it now — it is never
            retrievable later, by you or by anyone.
        </Banner>
        <p class="receipt" data-no-i18n>{{ grouped }}</p>
        <div class="cluster">
            <Btn v-if="copyable" variant="secondary" size="sm" icon="copy" @click="copy">
                {{ copied ? 'Copied ✓' : 'Copy receipt' }}
            </Btn>
            <a v-if="resultsHref" :href="resultsHref">
                Self-audit in the public count record
                <Icon name="arrow-right" size="sm" />
            </a>
        </div>
    </div>
</template>
