<script setup>
/**
 * ShellV2/TourBar — the guided-tour strip (ported from mockups/v3 shell-v2.js
 * renderTourBar). Rides as the second row of the floating header whenever the
 * tour mode is armed; renders nothing otherwise. All state comes from
 * useTour() (tour-as-a-MODE — session-persistent, follows navigation).
 *
 * A2 ruling (2026-07-29): every page is a stop. When the current page IS a
 * registered stop the bar names it ("step N of M"); when it isn't, the bar
 * still rides — it names the page you're on and its Back / Next return you to
 * the marked trail. The bar shows on `active` alone, never gated on `stop`.
 */
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import { useTour } from '@/composables/useTour.js';

const { active, onPath, stop, currentTitle, stepNumber, total, progressPct, backHref, nextHref, exit } = useTour();
</script>

<template>
    <div v-if="active" class="tour-bar" role="navigation" aria-label="Guided tour">
        <div class="tour-bar-text">
            <span v-if="onPath" class="tour-step"><Icon name="map" size="sm" /> Guided tour · step {{ stepNumber }} of {{ total }}</span>
            <span v-else class="tour-step"><Icon name="map" size="sm" /> Guided tour · exploring</span>
            <template v-if="onPath && stop">
                <strong class="tour-title">{{ stop.title }}</strong>
                <span class="tour-blurb">{{ stop.blurb }}</span>
            </template>
            <template v-else>
                <strong class="tour-title">{{ currentTitle || 'This page' }}</strong>
                <span class="tour-blurb">You’re off the marked trail — Back and Next return you to the walkthrough.</span>
            </template>
        </div>
        <div class="tour-bar-nav">
            <Link v-if="backHref" class="btn btn--ghost btn--sm" :href="backHref">
                <Icon name="chevron-left" size="sm" /> Back
            </Link>
            <Link v-if="nextHref" class="btn btn--primary btn--sm" :href="nextHref">
                Next <Icon name="chevron-right" size="sm" />
            </Link>
            <button v-else type="button" class="btn btn--primary btn--sm" @click="exit">
                Finish <Icon name="check" size="sm" />
            </button>
            <Link class="btn btn--ghost btn--sm" href="/tour">
                <Icon name="list-checks" size="sm" /> All steps
            </Link>
            <a class="tour-exit" href="#" @click.prevent="exit">Exit</a>
        </div>
        <div class="tour-prog" aria-hidden="true"><i :style="{ inlineSize: progressPct + '%' }"></i></div>
    </div>
</template>
