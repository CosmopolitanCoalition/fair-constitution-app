<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
defineOptions({ layout: AppShellV2 });
const { t } = useI18n();
const props = defineProps({ surface: Object, selectedPlace: Object, section: String, rooms: Array, commons: Array, pagination: Object });
const tabs = [
    ['chambers', t('c_civic.directory.tab_chambers', 'Chambers')],
    ['committees', t('c_civic.directory.tab_committees', 'Committees')],
    ['courts', t('c_civic.directory.tab_courts', 'Court hearings')],
    ['boards', t('c_civic.directory.tab_boards', 'My board rooms')],
];
const scope = () => props.selectedPlace ? '&jurisdiction=' + encodeURIComponent(props.selectedPlace.slug) : '';
</script>

<template>
    <Head :title="t('c_civic.directory.head_title', 'Live rooms')" />
    <div class="rooms-directory">
        <header><h1>{{ t('c_civic.directory.title', 'Live rooms') }}</h1><p>{{ t('c_civic.directory.lede', 'Find a place to talk, attend a hearing or join your institution. Rooms support text, voice and video.') }}</p></header>
        <nav class="room-nav" :aria-label="t('c_civic.directory.nav_location', 'Room location')">
            <strong v-if="selectedPlace">{{ selectedPlace.name }}</strong>
            <Link v-if="selectedPlace" :href="'/jurisdictions/' + selectedPlace.slug">{{ t('c_civic.directory.place_overview', 'Place overview') }}</Link>
            <Link href="/jurisdictions">{{ t('c_civic.directory.choose_another', 'Choose another place') }}</Link>
            <Link href="/civic/rooms">{{ t('c_civic.directory.private_messages', 'Private messages & groups') }}</Link>
        </nav>
        <p v-if="!selectedPlace">{{ t('c_civic.directory.pick_place_a', 'Choose a place from the world browser, then open') }} <strong>{{ t('c_civic.directory.live_rooms_term', 'Live rooms') }}</strong> {{ t('c_civic.directory.pick_place_b', 'in its place tools. Your residence does not restrict the public rooms you can visit.') }}</p>
        <div v-if="commons.length" class="room-grid">
            <article v-for="room in commons" :key="room.href"><h2>{{ room.title }}</h2><p>{{ room.detail }}</p><Link :href="room.href">{{ t('c_civic.directory.open_room_arrow', 'Open room →') }}</Link></article>
        </div>
        <nav class="room-nav room-tabs" :aria-label="t('c_civic.directory.nav_kinds', 'Kinds of room')">
            <Link v-for="[key, label] in tabs" :key="key" :href="'/rooms?section=' + key + scope()" :aria-current="section === key ? 'page' : undefined">{{ label }}</Link>
        </nav>
        <p v-if="section === 'boards'">{{ t('c_civic.directory.boards_note', 'Only boards where you currently hold a seat appear here. Their rooms remain private.') }}</p>
        <div class="room-grid">
            <article v-for="room in rooms" :key="room.id"><h2>{{ room.title }}</h2><p>{{ room.detail }}</p><Link :href="room.href">{{ room.action ?? t('c_civic.directory.open_room', 'Open room') }} →</Link></article>
        </div>
        <p v-if="!rooms.length && (selectedPlace || section === 'boards')">{{ t('c_civic.directory.no_rooms', 'No rooms in this section. Try another kind of room or another place.') }}</p>
        <nav class="room-nav" :aria-label="t('c_civic.directory.nav_pages', 'Room pages')">
            <Link v-if="pagination.previous" :href="pagination.previous">{{ t('c_civic.directory.previous_rooms', 'Previous rooms') }}</Link>
            <Link v-if="pagination.next" :href="pagination.next">{{ t('c_civic.directory.more_rooms', 'More rooms') }}</Link>
        </nav>
    </div>
</template>

<style scoped>
.rooms-directory { display: grid; gap: 1.5rem; }
.rooms-directory header p { max-width: 48rem; }
.room-nav { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem 1.4rem; }
.rooms-directory a { display: inline-flex; align-items: center; min-height: 44px; }
.rooms-directory a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.room-tabs { border-bottom: 1px solid var(--gov-border); }
.room-tabs [aria-current="page"] { border-bottom: 3px solid var(--gov-accent); font-weight: bold; }
.room-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(min(100%,19rem),1fr)); gap: 1rem; }
article { padding: 1.2rem; border: 1px solid var(--gov-border); border-radius: .6rem; background: var(--gov-surface); }
article h2 { font-size: 1.2rem; overflow-wrap: anywhere; }
article p { color: var(--gov-text-muted); }
</style>
