import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';
import { computed, ref, reactive } from 'vue';
import { nextInterval } from '../../resources/js/composables/liveRoomPolicy.js';

async function page(filename, props, exposed) {
    const file = await readFile(new URL('../../resources/js/Pages/Legislature/' + filename, import.meta.url), 'utf8');
    const source = file.match(/<script setup>([\s\S]*?)<\/script>/)[1];
    const polls = [], forms = [], posts = [];
    const context = vm.createContext({ defineOptions() {}, defineProps: () => props });
    const mod = new vm.SourceTextModule(source + '\nexport { ' + exposed.join(', ') + ' };', { context });
    await mod.link(name => {
        let values = { default: {} };
        if (name === 'vue') values = { computed, ref, watch() {} };
        else if (name === '@inertiajs/vue3') values = { Link: {}, router: { post: (...args) => posts.push(args) }, usePage: () => ({ props: {} }), useForm: initial => {
            const form = { ...initial, errors: {}, processing: false, transform() { return this; }, post: (...args) => posts.push(args), reset() {} };
            forms.push(form); return form;
        } };
        else if (name === 'vue-i18n') values = { useI18n: () => ({ t: (key, fallback) => fallback }) };
        else if (name.endsWith('useLiveRoom')) values = { useLiveRoom: options => { polls.push(options); return { isStale: ref(false) }; } };
        else if (name.endsWith('useAnnounce')) values = { default: () => ({ announce() {} }) };
        else if (name.endsWith('roomPresentation.js')) values = { personLabel: person => person.display_name || person.identity };
        return new vm.SyntheticModule(Object.keys(values), function () { for (const [key, value] of Object.entries(values)) this.setExport(key, value); }, { context });
    });
    await mod.evaluate();
    return { polls, forms, posts, api: mod.namespace };
}

test('hearing discussion keeps refreshing and posting after formal adjournment without a second poll stream', async () => {
    const props = reactive({ status: { state: 'open' }, presence: [], displayNames: {}, urls: { messages: '/rooms/committee/selected/messages' } });
    const f = await page('LiveCivicRoom.vue', props, ['sendMessage']);
    assert.equal(f.polls.length, 1);
    props.status.state = 'adjourned';
    assert.equal(nextInterval(f.polls[0].isLive(), f.polls[0].cadenceMs), 5000);
    for (const key of ['chat', 'chatAvailable', 'voice', 'can', 'status']) assert.equal(f.polls[0].keys.includes(key), true);
    f.forms[0].body = 'Discussion after the hearing';
    f.api.sendMessage();
    assert.equal(f.posts[0][0], '/rooms/committee/selected/messages');
    f.forms[0].processing = true;
    assert.equal(f.polls[0].busy(), true);
});

test('the official workspace files testimony and agenda against the server-selected hearing', async () => {
    const props = reactive({ meeting: { id: 'selected-hearing' }, surface: { forms: [] }, committee: {}, urls: {} });
    const f = await page('CommitteeDetail.vue', props, ['submitTestimony', 'submitAgenda']);
    f.api.submitTestimony();
    f.api.submitAgenda();
    assert.equal(f.posts[0][0], '/meetings/selected-hearing/testimony');
    assert.equal(f.posts[1][0], '/meetings/selected-hearing/agenda');
});

test('committee hand control follows the canonical own identity and sends only raise or lower', async () => {
    const props = reactive({ status: { state: 'open' }, voice: { myMxid: '@own:fixture' }, queue: [{ handle: '@other:fixture' }], urls: { raiseHand: '/rooms/committee/selected/raise-hand' } });
    const f = await page('LiveCivicRoom.vue', props, ['toggleHand', 'myHandRaised']);
    assert.equal(f.api.myHandRaised.value, false);
    f.api.toggleHand();
    assert.equal(f.posts[0][1].action, 'raise');
    props.queue.push({ handle: '@own:fixture' });
    assert.equal(f.api.myHandRaised.value, true);
    f.api.toggleHand();
    assert.equal(f.posts[1][0], '/rooms/committee/selected/raise-hand');
    assert.equal(f.posts[1][1].action, 'lower');
    assert.equal(Object.hasOwn(f.posts[1][1], 'handle'), false);
});
