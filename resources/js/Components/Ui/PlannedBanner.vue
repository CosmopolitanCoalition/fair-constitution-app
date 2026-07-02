<script setup>
/**
 * Ui/PlannedBanner — the "Planned — a preview" banner (verbatim port of
 * mockups/v3 shell-v2.js plannedBanner). Marks a surface that is designed
 * ahead of the build: honest to the player, nothing live, no real money.
 */
defineProps({
    /** Optional replacement for the default explanation sentence. */
    extra: { type: String, default: null },
});
</script>

<template>
    <div class="banner banner--demo planned-banner">
        <div>
            <span class="banner-title">Planned — a preview.</span>
            {{ extra || 'This part of the world is designed ahead of the build. Nothing here is live yet, and no real money is anywhere.' }}
        </div>
    </div>
</template>
