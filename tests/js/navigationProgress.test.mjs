import assert from 'node:assert/strict';
import test from 'node:test';
import { createNavigationProgress, isPageNavigation } from '../../resources/js/lib/navigationProgress.js';

function fixture() {
    let now = 0;
    let id = 0;
    let state;
    const timers = new Map();
    const progress = createNavigationProgress(value => { state = value; }, {
        schedule: (callback, delay) => { timers.set(++id, { callback, at: now + delay }); return id; },
        cancel: key => timers.delete(key),
    });
    return { progress, state: () => state, advance: (ms) => {
        now += ms;
        for (const [key, timer] of timers) if (timer.at <= now) { timers.delete(key); timer.callback(); }
    } };
}

test('foreground navigation remains visible until its response and component finish', () => {
    const f = fixture();
    const visit = { showProgress: true };
    f.progress.startVisit(visit);
    f.advance(99);
    assert.equal(f.state().visible, false);
    f.advance(1);
    assert.equal(f.state().visible, true);
    f.advance(8000);
    assert.equal(f.state().slow, true);
    f.progress.finishVisit(visit);
    assert.equal(f.state().visible, false);
});

test('background polls and prefetch neither flash nor clear foreground feedback', () => {
    const f = fixture();
    const background = { showProgress: false, async: true };
    const prefetch = { prefetch: true };
    f.progress.startVisit(background);
    f.progress.startVisit(prefetch);
    f.advance(10000);
    assert.equal(f.state(), undefined);
    const visit = {};
    f.progress.startVisit(visit);
    f.advance(100);
    f.progress.finishVisit(background);
    f.progress.finishVisit(prefetch);
    assert.equal(f.state().visible, true);
    f.progress.finishVisit(visit);
    assert.equal(f.state().visible, false);
});

test('out-of-order and cancelled visits cannot clear a newer request or leave timers behind', () => {
    const f = fixture();
    const first = {};
    const next = {};
    f.progress.startVisit(first);
    f.progress.startVisit(next);
    f.progress.finishVisit(first);
    f.advance(100);
    assert.equal(f.state().visible, true);
    f.progress.finishVisit(next);
    f.advance(10000);
    assert.equal(f.state().visible, false);
    assert.equal(f.state().slow, false);
    f.progress.startVisit({});
    f.progress.reset();
    f.advance(10000);
    assert.equal(f.state().visible, false);
});

test('document navigation can be dismissed, restored from back cache, and restarted after a failure', () => {
    const f = fixture();
    f.progress.startDocument();
    f.advance(100);
    assert.equal(f.state().native, true);
    f.progress.finishDocument();
    assert.equal(f.state().visible, false);
    f.progress.startDocument();
    f.progress.reset();
    f.advance(10000);
    assert.equal(f.state().visible, false);
    const visit = { url: 'http://localhost:8080/civic', method: 'get' };
    f.progress.startVisit(visit);
    f.progress.failRequest({ url: 'http://localhost:8080/poll', method: 'get' });
    assert.equal(f.state().failed, false, 'a silent background failure must not claim the page request failed');
    f.progress.failRequest({ url: visit.url, method: 'get' });
    assert.equal(f.state().failed, false, 'even a same-URL silent reload is a different request');
    f.progress.failRequest({ headers: visit.headers });
    f.progress.finishVisit(visit);
    assert.equal(f.state().failed, true);
    f.progress.startVisit({});
    assert.equal(f.state().failed, false);
});

test('an overlapping document navigation remains dismissible after the app request finishes', () => {
    const f = fixture();
    const visit = {};
    f.progress.startVisit(visit);
    f.progress.startDocument();
    f.progress.finishVisit(visit);
    f.advance(8000);
    assert.equal(f.state().native, true);
    assert.equal(f.state().slow, true);
    f.progress.finishDocument();
    assert.equal(f.state().visible, false);
});

test('only same-window page links get native loading feedback', () => {
    const current = 'http://localhost:8080/civic';
    const tracks = (href, extra = {}) => isPageNavigation({ href, ...extra }, current);
    assert.equal(tracks('/elections/123'), true);
    assert.equal(tracks('/civic?tab=events'), true);
    assert.equal(tracks('/civic'), true, 'same URL without a hash reloads the page');
    for (const href of ['#main', '/civic#main', 'mailto:civic@example.org', 'https://example.org', '/api/exports/file', '/report.csv', '/map.pmtiles']) {
        assert.equal(tracks(href), false, href);
    }
    for (const extra of [{ target: '_blank' }, { target: 'report' }, { prevented: true }, { modified: true }, { download: true }, { optOut: true }]) {
        assert.equal(tracks('/elections/123', extra), false, JSON.stringify(extra));
    }
});
