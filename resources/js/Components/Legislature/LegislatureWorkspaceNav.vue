<script setup>
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

defineProps({ workspace: { type: Object, required: true }, active: { type: String, required: true } });
const { t } = useI18n();
const text = key => t('c_legislature_workspace.' + key, key === 'sessions' ? 'Session archive' : key);
const tabs = ['overview', 'chamber', 'session', 'speaker'];
const more = ['sessions', 'committees', 'oversight', 'referendums', 'settings', 'rooms'];
</script>

<template>
    <div class="leg-workspace">
        <p v-if="workspace.place" class="leg-place"><Link :href="workspace.place.href">{{ workspace.place.name }}</Link></p>
        <nav class="leg-tabs" :aria-label="text('navigation')">
            <template v-for="key in tabs" :key="key">
                <Link v-if="workspace[key]" :href="workspace[key]" :aria-current="active === key ? 'page' : undefined">{{ text(key) }}</Link>
                <span v-else class="leg-restricted">{{ text(key) }} <small>{{ text('member_access') }}</small></span>
            </template>
        </nav>
        <nav class="leg-tools" :aria-label="text('related_work')">
            <Link class="leg-map-link" :href="workspace.maps">{{ text('maps') }}</Link>
            <Link :href="workspace.bills">{{ text('bills') }}</Link>
            <details>
                <summary>{{ text('more') }}</summary>
                <div class="leg-more">
                    <template v-for="key in more" :key="key"><Link v-if="workspace[key]" :href="workspace[key]">{{ text(key) }}</Link></template>
                </div>
            </details>
        </nav>
    </div>
</template>

<style scoped>
.leg-workspace { min-inline-size: 0; }
.leg-place { margin: 0 0 .4rem; }
.leg-place a, .leg-tools > a, .leg-tools summary { display: inline-flex; align-items: center; min-height: 2.75rem; }
.leg-tabs { display: flex; flex-wrap: wrap; gap: .4rem; border-bottom: 1px solid var(--gov-border); padding-bottom: .5rem; }
.leg-tabs > a, .leg-restricted { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .4rem; min-height: 2.75rem; padding: .55rem .8rem; border-radius: var(--radius-md); }
.leg-tabs a { text-decoration: none; color: inherit; }
.leg-tabs a[aria-current="page"] { background: var(--gov-surface-2); box-shadow: inset 0 0 0 1px var(--gov-border); font-weight: 700; }
.leg-restricted { color: var(--gov-fg-muted); }
.leg-restricted small { font-size: .75rem; }
.leg-tools { display: flex; flex-wrap: wrap; align-items: flex-start; gap: .35rem 1.2rem; font-size: var(--text-sm); }
.leg-map-link { font-weight: 700; }
.leg-tools summary { cursor: pointer; text-decoration: underline; }
.leg-more { display: flex; flex-wrap: wrap; gap: .5rem 1rem; padding-block: .3rem .6rem; }
.leg-more a { display: inline-flex; align-items: center; min-height: 2.75rem; }
.leg-workspace a:focus-visible, .leg-tools summary:focus-visible { outline: 3px solid var(--gov-accent, #a57924); outline-offset: 3px; }
</style>
