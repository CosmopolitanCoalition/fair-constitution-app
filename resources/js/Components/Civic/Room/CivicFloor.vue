<script setup>
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ParticipantTile from './ParticipantTile.vue';
import Avatar from '@/Components/Ui/Avatar.vue';
import { ROOM_LAYOUTS, personInitials, personLabel, roomKind, roomSeating, visibleRoomPeople } from './roomPresentation.js';

const props = defineProps({
    variant: { type: String, default: 'commons' },
    roster: { type: Array, default: () => [] },
    participants: { type: Array, default: () => [] },
    floorHolder: { type: String, default: null },
    audioOutput: { type: String, default: '' },
});
const linear = ref(false);
const { t } = useI18n();
const text = (key, fallback) => t('c_rooms.' + key, fallback);
const expanded = ref({});
const kind = computed(() => roomKind(props.variant));
const zones = computed(() => roomSeating(props));
const visiblePeople = (zone) => visibleRoomPeople(zone.people, expanded.value[zone.id] || linear.value);
const hasHiddenSeats = (zone) => visibleRoomPeople(zone.people).length < zone.people.length;
</script>

<template>
    <section class="civic-floor" :class="[`civic-floor--${kind}`, { 'civic-floor--linear': linear }]" :aria-label="text('title.' + kind, ROOM_LAYOUTS[kind].title)">
        <header class="floor-header">
            <div>
                <h2>{{ text('title.' + kind, ROOM_LAYOUTS[kind].title) }}</h2>
                <p>{{ text('seating_hint', 'Seats show assigned roles. A call badge marks a connected participant.') }}</p>
            </div>
            <button class="floor-toggle" type="button" :aria-pressed="linear" @click="linear = !linear">
                {{ linear ? text('show_floor', 'Show floor layout') : text('show_list', 'Show seating list') }}
            </button>
        </header>
        <div class="floor-plan">
            <section v-for="zone in zones" :key="zone.id" class="floor-zone" :class="`floor-zone--${zone.id}`" :aria-label="text('zone.' + kind + '.' + zone.id, zone.label)">
                <h3>{{ text('zone.' + kind + '.' + zone.id, zone.label) }} <span v-if="zone.people.length">({{ zone.people.length }})</span></h3>
                <ul v-if="zone.people.length" class="floor-seats">
                    <li v-for="person in visiblePeople(zone)" :key="person.identity" :class="{ 'floor-seat--recognized': person.holdsFloor }">
                        <ParticipantTile v-if="person.inCall"
                            :identity="person.identity" :display-name="personLabel(person)"
                            :is-local="person.isLocal" :is-speaking="person.isSpeaking"
                            :video-track="person.videoTrack" :audio-track="person.audioTrack" :audio-output="audioOutput" />
                        <div v-else class="floor-seat">
                            <Avatar :initials="personInitials(person)" />
                            <strong>{{ personLabel(person) }}</strong>
                        </div>
                        <span class="floor-seat-state">
                            {{ person.holdsFloor ? text('recognized', 'Recognized to speak') : person.inCall ? text('in_call', 'In call') : text('assigned', 'Assigned seat') }}
                            <template v-if="person.seat"> · {{ text('seat.' + person.seat, String(person.seat).replaceAll('_', ' ')) }}</template>
                        </span>
                    </li>
                </ul>
                <p v-else class="floor-empty">{{ zone.id === 'floor' ? text('no_speaker', 'No speaker in this position.') : text('no_assignment', 'No participants assigned here.') }}</p>
                <button v-if="!linear && hasHiddenSeats(zone)" type="button" class="floor-toggle" :aria-expanded="Boolean(expanded[zone.id])" @click="expanded[zone.id] = !expanded[zone.id]">
                    {{ expanded[zone.id] ? text('fewer_seats', 'Show fewer seats') : text('all_seats', 'Show all seats') + ' (' + zone.people.length + ')' }}
                </button>
            </section>
        </div>
    </section>
</template>

<style scoped>
.civic-floor { --floor-ink: #e9eff5; --floor-line: #527083; color: var(--floor-ink); background: #122831; border: 1px solid var(--floor-line); border-radius: 1rem; padding: clamp(.75rem, 2vw, 1.5rem); }
.floor-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.floor-header h2 { color: inherit; font-size: 1.125rem; margin: 0; }
.floor-header p { color: #c4d3dc; font-size: .8rem; margin: .4rem 0 0; max-width: 40rem; }
.floor-toggle { border: 1px solid #87a6b8; border-radius: .45rem; background: #1d3945; color: #fff; padding: .5rem .75rem; font-size: .8rem; cursor: pointer; }
.floor-toggle:focus-visible { outline: 3px solid #f0d58b; outline-offset: 3px; }
.floor-plan { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .85rem; }
.floor-zone { min-width: 0; padding: 1rem; background: #1a3540; border: 1px solid var(--floor-line); border-radius: .6rem; text-align: center; }
.floor-zone h3 { margin: 0 0 .75rem; font-size: .8rem; font-weight: 600; color: #f0d58b; }
.floor-zone h3 span { color: #c4d3dc; }
.floor-zone--dais { grid-column: 1 / -1; width: min(100%, 38rem); justify-self: center; border-bottom: 4px solid #b79860; }
.floor-zone--floor { grid-column: 1 / -1; width: min(100%, 28rem); justify-self: center; background: #234650; }
.floor-zone--members, .floor-zone--gallery { grid-column: 1 / -1; }
.civic-floor--legislature .floor-zone--members { border-radius: 2rem 2rem 6rem 6rem; padding-bottom: 2rem; border-bottom: 8px solid #527083; }
.civic-floor--committee .floor-zone--members, .civic-floor--board .floor-zone--members { border: 5px solid #806a49; border-radius: 2rem; }
.civic-floor--court .floor-zone--floor { grid-column: 2; justify-self: end; }
.floor-zone--gallery { border-style: dashed; background: transparent; }
.floor-seats { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 8.5rem), 1fr)); gap: .7rem; }
.floor-seats li { min-width: 0; border-radius: .5rem; background: #102b35; padding: .45rem; }
.floor-seat { min-height: 5.25rem; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .5rem; }
.floor-seat strong { font-size: .8rem; overflow-wrap: anywhere; }
.floor-seat-state { display: block; font-size: .7rem; color: #cfdae1; margin-top: .35rem; }
.floor-seat--recognized { outline: 2px solid #f0d58b; }
.floor-empty { color: #c4d3dc; font-size: .8rem; margin: .5rem 0; }
.floor-zone > .floor-toggle { margin-top: .75rem; }
.civic-floor--linear .floor-plan { display: block; }
.civic-floor--linear .floor-zone { border-radius: .5rem; width: 100%; margin-bottom: .85rem; }
.civic-floor--linear .floor-seats { grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); }
@media (max-width: 600px) { .floor-plan { display: flex; flex-direction: column; } .floor-zone { width: 100%; } .civic-floor--legislature .floor-zone--members { border-radius: 1rem; } }
</style>
