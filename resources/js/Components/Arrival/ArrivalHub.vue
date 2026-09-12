<script setup>
import { computed, onMounted } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Icon from '@/Components/Ui/Icon.vue';

const page = usePage();
const { t: globalT } = useI18n({ useScope: 'global' });
const { t } = useI18n({
    useScope: 'local',
    fallbackLocale: 'en',
    messages: { en: {
        title: 'Welcome',
        eyebrow: 'Learn government by taking part',
        intro: 'Explore a simulated world, follow its public decisions, and discover how civic roles work.',
        start: 'Start exploring',
        places: 'Explore places & maps',
        placesHint: 'Browse the jurisdiction tree from the world to local communities. Open a place to find its government and legislative district maps.',
        placesAction: 'Choose a place',
        roles: 'Explore civic roles',
        rolesHint: 'See the work of a resident, legislator, judge, committee chair, election board member, or instance operator.',
        rolesAction: 'Choose a role',
        everyday: 'Take part in everyday life',
        community: 'Community',
        communityHint: 'Public discussions, live rooms, and private messages.',
        economy: 'Work & trade',
        economyHint: 'Find work, trade goods and services, and manage your wallet.',
        learn: 'Learn',
        learnHint: 'Follow a guided journey, read a lesson, or watch a video.',
        accountTitle: 'Ready to take part?',
        accountHint: 'Create an account to participate. You can browse places and explore roles before joining.',
        register: 'Create an account',
        login: 'Sign in',
        returnTitle: 'Continue your day',
        returnHint: 'Find your upcoming events and current civic activity.',
        today: 'Go to Today',
        setup: 'Opening instance setup…',
        setupAction: 'Continue to setup',
    } },
});

const instance = computed(() => page.props.shellInstance ?? page.props.instance);
const setupComplete = computed(() => instance.value?.setupComplete ?? true);
const appName = computed(() => instance.value?.name || globalT('app.name'));
const signedIn = computed(() => Boolean(page.props.auth?.user));
const viewedPlace = computed(() => {
    const resolved = page.props.selectedPlace?.id || page.props.jurisdictionContext?.current?.id;
    if (resolved) return resolved;
    // Arrival URLs may carry a shared place without fetching it themselves.
    // Both receiving pages resolve UUIDs and slugs before using the place.
    const requested = new URL(page.url, 'http://localhost').searchParams.get('jurisdiction');
    return requested && requested.length <= 255 ? requested : null;
});
const destination = href => viewedPlace.value && ['/explore', '/civic/square'].includes(href)
    ? href + '?jurisdiction=' + encodeURIComponent(viewedPlace.value)
    : href;

onMounted(() => {
    if (!setupComplete.value) router.visit('/setup', { replace: true });
});

const entryPoints = [
    { key: 'places', icon: 'map', href: '/jurisdictions' },
    { key: 'roles', icon: 'landmark', href: '/explore' },
];
const everydayHubs = [
    { key: 'community', icon: 'users', href: '/civic/square' },
    { key: 'economy', icon: 'briefcase', href: '/economy' },
    { key: 'learn', icon: 'book-open', href: '/learn' },
];
</script>

<template>
    <Head :title="t('title')" />

    <div v-if="!setupComplete" class="arrival-setup">
        <p role="status">{{ t('setup') }}</p>
        <Link href="/setup" class="arrival-button">{{ t('setupAction') }}</Link>
    </div>

    <div v-else class="arrival">
        <header class="arrival-header">
            <p class="arrival-eyebrow">{{ t('eyebrow') }}</p>
            <h1>{{ appName }}</h1>
            <p class="arrival-intro">{{ t('intro') }}</p>
        </header>

        <section aria-labelledby="arrival-start">
            <h2 id="arrival-start">{{ t('start') }}</h2>
            <div class="arrival-entries">
                <Link v-for="entry in entryPoints" :key="entry.key" :href="destination(entry.href)" class="arrival-entry">
                    <Icon :name="entry.icon" />
                    <h3>{{ t(entry.key) }}</h3>
                    <p>{{ t(`${entry.key}Hint`) }}</p>
                    <span class="arrival-entry-action">{{ t(`${entry.key}Action`) }} <Icon name="arrow-right" size="sm" /></span>
                </Link>
            </div>
        </section>

        <section aria-labelledby="arrival-everyday">
            <h2 id="arrival-everyday">{{ t('everyday') }}</h2>
            <nav :aria-label="t('everyday')" class="arrival-hubs">
                <Link v-for="hub in everydayHubs" :key="hub.key" :href="destination(hub.href)" class="arrival-hub">
                    <Icon :name="hub.icon" />
                    <span><strong>{{ t(hub.key) }}</strong><span class="arrival-hint">{{ t(`${hub.key}Hint`) }}</span></span>
                    <Icon name="arrow-right" size="sm" />
                </Link>
            </nav>
        </section>

        <section aria-labelledby="arrival-account" class="arrival-account">
            <div>
                <h2 id="arrival-account">{{ t(signedIn ? 'returnTitle' : 'accountTitle') }}</h2>
                <p class="arrival-hint">{{ t(signedIn ? 'returnHint' : 'accountHint') }}</p>
            </div>
            <div class="arrival-account-actions">
                <Link v-if="signedIn" href="/civic" class="arrival-button arrival-button--primary">{{ t('today') }} <Icon name="arrow-right" size="sm" /></Link>
                <template v-else>
                    <Link href="/register" class="arrival-button arrival-button--primary">{{ t('register') }}</Link>
                    <Link href="/login" class="arrival-button">{{ t('login') }}</Link>
                </template>
            </div>
        </section>
    </div>
</template>

<style scoped>
.arrival { display: grid; gap: var(--space-6, 1.5rem); max-inline-size: 72rem; margin-inline: auto; }
.arrival-header { max-inline-size: 46rem; }
.arrival-eyebrow { margin: 0 0 var(--space-2); font-size: .875rem; font-weight: 600; color: var(--gov-accent); }
.arrival h1 { margin: 0; font-size: clamp(1.75rem, 4vw, 3rem); line-height: 1.2; overflow-wrap: anywhere; }
.arrival-intro { margin-block: var(--space-3) 0; font-size: 1.125rem; line-height: 1.6; color: var(--gov-fg-muted); }
.arrival h2 { margin: 0 0 var(--space-3); font-size: 1.05rem; }
.arrival-entries { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4); }
.arrival-entry { display: flex; flex-direction: column; align-items: flex-start; gap: var(--space-3); padding: var(--space-5, 1.25rem); border: 1px solid var(--gov-border); border-radius: var(--radius-md, .5rem); color: inherit; background: var(--gov-surface); text-decoration: none; }
.arrival-entry > .icon, .arrival-hub > .icon { color: var(--gov-accent); flex-shrink: 0; }
.arrival-entry h3 { margin: 0; font-size: 1.25rem; }
.arrival-entry p { margin: 0; color: var(--gov-fg-muted); line-height: 1.6; }
.arrival-entry-action { display: inline-flex; align-items: center; gap: .5rem; margin-block-start: auto; padding-block-start: var(--space-2); color: var(--gov-accent); font-weight: 600; }
.arrival-hubs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-3); }
.arrival-hub { display: flex; align-items: flex-start; gap: var(--space-3); padding: var(--space-4); border: 1px solid var(--gov-border); border-radius: var(--radius-md, .5rem); color: inherit; text-decoration: none; }
.arrival-hub > span { flex: 1; min-inline-size: 0; }
.arrival-hint { display: block; margin-block: .35rem 0; color: var(--gov-fg-muted); font-size: .875rem; line-height: 1.6; }
.arrival-account { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: var(--space-4); padding-block-start: var(--space-5, 1.25rem); border-block-start: 1px solid var(--gov-border); }
.arrival-account h2 { margin-block-end: 0; }
.arrival-account > div:first-child { flex: 1 1 20rem; }
.arrival-account-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }
.arrival-button { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-block-size: 44px; padding: .625rem 1rem; border: 1px solid var(--gov-accent); border-radius: var(--radius-md, .5rem); color: var(--gov-accent); font-weight: 600; text-decoration: none; }
.arrival-button--primary { background: color-mix(in oklch, var(--gov-accent) 12%, var(--gov-surface)); }
.arrival-entry:hover, .arrival-hub:hover { border-color: var(--gov-accent); }
.arrival a:focus-visible, .arrival-setup a:focus-visible { outline: 3px solid var(--gov-accent); outline-offset: 3px; }
.arrival-setup { display: grid; justify-items: center; gap: var(--space-3); padding-block: var(--space-8, 2rem); }
@media (max-width: 900px) { .arrival-hubs { grid-template-columns: 1fr; } }
@media (max-width: 600px) { .arrival-entries { grid-template-columns: 1fr; } .arrival-account-actions { inline-size: 100%; } .arrival-button { flex: 1; } }
</style>
