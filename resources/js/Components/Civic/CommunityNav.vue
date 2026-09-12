<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({ jurisdictionId: { type: String, default: '' } });
const page = usePage();
const { t } = useI18n();
const text = (key, fallback) => t('c_community.' + key, fallback);
const current = computed(() => String(page.url).split('?')[0]);
const scope = computed(() => {
    const resolved = props.jurisdictionId || page.props.selectedPlace?.id || page.props.jurisdictionContext?.current?.id;
    if (resolved) return resolved;
    const query = new URL(String(page.url), 'http://localhost').searchParams.get('jurisdiction') || '';
    // Live Matrix endpoints take UUIDs. A slug from a recorded-discussion URL
    // must resolve through the selected place before it can enter that path.
    return /^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i.test(query) ? query : '';
});
const liveHref = (path) => scope.value ? path + '?jurisdiction=' + encodeURIComponent(scope.value) : path;
const links = computed(() => [
    { label: text('public', 'Public discussions'), path: '/civic/square', href: liveHref('/civic/square') },
    { label: text('governance', 'Governance discussions'), path: '/civic/halls', href: liveHref('/civic/halls') },
    { label: text('live_rooms', 'Live rooms'), path: '/rooms', href: liveHref('/rooms') },
    { label: text('messages', 'Messages'), path: '/civic/rooms', href: '/civic/rooms' },
]);
const active = (path) => current.value === path || (path === '/civic/rooms' && current.value.startsWith(path + '/'));
</script>

<template>
    <nav class="community-nav" :aria-label="text('title', 'Community')">
        <div class="community-nav-links">
            <Link v-for="item in links" :key="item.path" :href="item.href" :aria-current="active(item.path) ? 'page' : undefined">
                {{ item.label }}
            </Link>
        </div>
        <p v-if="['/civic/square', '/civic/halls'].includes(current) && !page.props.selectedPlace">{{ text('association_scope', 'Showing discussions from your residency associations.') }}</p>
    </nav>
</template>

<style scoped>
.community-nav { margin-bottom: var(--space-3); }
.community-nav-links { display: flex; flex-wrap: wrap; gap: .35rem; border-bottom: 1px solid var(--border, #d5dbe0); padding-bottom: .6rem; }
.community-nav a { display: inline-flex; align-items: center; min-height: 2.75rem; border: 1px solid transparent; border-radius: .5rem; padding: .45rem .75rem; text-decoration: none; font-size: .875rem; color: inherit; }
.community-nav a:hover { text-decoration: underline; }
.community-nav a[aria-current="page"] { background: var(--surface-raised, #edf2f4); color: var(--text-primary, #18343d); border-color: var(--border, #aabcc5); font-weight: 650; }
.community-nav a:focus-visible { outline: 3px solid var(--accent, #a57924); outline-offset: 2px; }
.community-nav p { margin: .65rem 0 0; font-size: .8rem; color: var(--text-secondary, #566573); }
</style>
