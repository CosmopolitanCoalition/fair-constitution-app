<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppShellV2 from '@/Layouts/AppShellV2.vue';
import PageScaffold from '@/Components/Surface/PageScaffold.vue';
import Icon from '@/Components/Ui/Icon.vue';
import CivicFloor from '@/Components/Civic/Room/CivicFloor.vue';
import { CIVIC_ROLES, EXPLORER_LINKS, EXPLORER_GLOBAL_LINKS, ROOM_GUIDES } from '@/registry/roleExplorer.js';

defineOptions({ layout: AppShellV2 });
const props = defineProps({ place: { type: Object, default: null }, destinations: { type: Object, default: () => ({}) } });
const page = usePage();
const { t } = useI18n();
const selected = computed(() => new URL(page.url, 'http://localhost').searchParams.get('role'));
const role = computed(() => CIVIC_ROLES.find(r => r.id === selected.value) ?? CIVIC_ROLES[0]);
const guide = computed(() => ROOM_GUIDES[role.value.room] ?? {});
const text = (key, fallback) => t('c_explore.' + key, fallback);
const roleTitle = r => text(r.id + '.title', r.title);
function selectRole(id) {
    router.get('/explore', { ...(props.place ? { jurisdiction: props.place.slug } : {}), role: id }, { preserveScroll: true, preserveState: true, replace: true });
}
const href = key => props.destinations[key] ?? EXPLORER_GLOBAL_LINKS[key] ?? null;
const home = computed(() => page.props.homeJurisdiction?.current);
</script>

<template>
    <PageScaffold :title="text('title', 'Explore civic roles')">
        <template #intro>{{ text('intro', 'Choose a perspective, open its workspace, and follow government in action.') }}</template>
        <div class="explorer-place">
            <div>
                <span class="eyebrow">{{ text('place_label', 'Your place to explore') }}</span>
                <strong>{{ place?.name ?? text('choose_place', 'Choose a place anywhere in the world') }}</strong>
            </div>
            <Link href="/jurisdictions" class="btn">{{ text('browse_places', 'Browse places') }}</Link>
            <Link v-if="!place && home" :href="'/explore?jurisdiction=' + encodeURIComponent(home.slug)" class="btn">
                {{ home.name }}
            </Link>
        </div>
        <div class="role-choices" role="group" :aria-label="text('choose_role', 'Choose a civic role')">
            <button v-for="choice in CIVIC_ROLES" :key="choice.id" type="button" :aria-pressed="choice.id === role.id"
                    class="role-choice" @click="selectRole(choice.id)">
                <Icon :name="choice.icon" size="sm" />{{ roleTitle(choice) }}
            </button>
        </div>
        <section class="role-workspace" aria-labelledby="role-heading">
            <div class="role-description">
                <h2 id="role-heading">{{ roleTitle(role) }}</h2>
                <p>{{ text(role.id + '.description', role.description) }}</p>
                <div class="role-actions">
                    <template v-for="key in role.destinations" :key="key">
                        <Link v-if="href(key)" :href="href(key)" class="btn btn--primary">
                            {{ text('link.' + key, EXPLORER_LINKS[key]) }}<Icon name="arrow-right" size="sm" />
                        </Link>
                    </template>
                    <Link :href="role.lesson" class="btn">{{ text('learn_role', 'Learn this role') }}</Link>
                </div>
                <p v-if="!place && role.destinations.some(key => !EXPLORER_GLOBAL_LINKS[key])" class="gloss">
                    {{ text('place_prompt', 'Choose a place to open its institutions and current activity.') }}
                </p>
                <p v-else-if="place && role.destinations.some(key => !href(key))" class="gloss">
                    {{ text('forming', 'Some institutions or elections have not formed in this place yet. Its overview shows what exists and the places around it.') }}
                </p>
            </div>
            <div v-if="role.room" class="role-floor">
                <h3>{{ text('room_heading', 'How the room is arranged') }}</h3>
                <p class="gloss">{{ text('room_guide_examples', 'These labeled seats are examples for learning the room. Enter a live room to see its actual participants and current speaker.') }}</p>
                <CivicFloor :key="role.room" :variant="role.room" :roster="guide.roster" :floor-holder="guide.floorHolder" :active-witness="guide.activeWitness" />
            </div>
        </section>
        <p class="gloss">{{ text('perspective_note', 'You can explore every perspective. Your account and public identity stay with you as you move between workspaces.') }}</p>
    </PageScaffold>
</template>

<style scoped>
.explorer-place { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; padding: 1rem; border: 1px solid var(--border, #ccd4d8); border-radius: .75rem; }
.explorer-place > div { display: grid; gap: .3rem; margin-inline-end: auto; }
.role-choices { display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: .5rem; }
.role-choice { display: flex; align-items: center; gap: .5rem; padding: .85rem; min-height: 48px; text-align: start; font: inherit; color: inherit; background: var(--surface, transparent); border: 1px solid var(--border, #ccd4d8); border-radius: .5rem; cursor: pointer; }
.role-choice[aria-pressed="true"] { outline: 2px solid var(--accent, #177b74); outline-offset: -2px; background: color-mix(in srgb, var(--accent, #177b74) 10%, transparent); }
.role-workspace { display: grid; gap: 1.5rem; }
.role-description { max-width: 60rem; }
.role-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-block: 1rem; }
.role-floor { min-width: 0; }
</style>
