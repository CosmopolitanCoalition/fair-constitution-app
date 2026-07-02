<script setup>
/**
 * ShellV2/TourBar — the guided-tour strip (ported from mockups/v3 shell-v2.js
 * renderTourBar). Rides as the second row of the floating header when the
 * tour mode is armed; renders nothing otherwise. All state comes from
 * useTour() (tour-as-a-MODE — session-persistent, follows navigation).
 */
import { Link } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import { useTour } from '@/composables/useTour.js';

const { active, stop, stepNumber, total, progressPct, backHref, nextHref, exit } = useTour();
</script>

<template>
    <div v-if="active && stop" class="tour-bar" role="navigation" aria-label="Guided tour">
        <div class="tour-bar-text">
            <span class="tour-step"><Icon name="map" size="sm" /> Guided tour · step {{ stepNumber }} of {{ total }}</span>
            <strong class="tour-title">{{ stop.title }}</strong>
            <span class="tour-blurb">{{ stop.blurb }}</span>
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
            <a class="tour-exit" href="#" @click.prevent="exit">Exit</a>
        </div>
        <div class="tour-prog" aria-hidden="true"><i :style="{ inlineSize: progressPct + '%' }"></i></div>
    </div>
</template>
