import test from 'node:test';
import assert from 'node:assert/strict';
import { githubReport, saveReportDraft, takeReportDraft, clearReportDraft } from '../../resources/js/lib/supportReport.js';

const form = { category:'bug', subject:'Keyboard & map', body:'Cannot type.\nExpected a keyboard.', ref:'/legislature/districts?token=private#selection' };
const repo='CosmopolitanCoalition/fair-constitution-app';
test('GitHub composer preserves written details and strips query/fragment diagnostics', () => {
    const issue=githubReport(repo,form,'https://demo.example.test','Something is broken');
    const url=new URL(issue.url);
    assert.equal(url.origin,'https://github.com');
    assert.equal(url.pathname,`/${repo}/issues/new`);
    assert.equal(url.searchParams.get('title'),form.subject);
    assert.match(url.searchParams.get('body'),/Cannot type\.\nExpected a keyboard\./);
    assert.match(issue.text,/Page: \/legislature\/districts\n/);
    assert.ok(!issue.text.includes('private'));
    assert.equal(issue.needsCopy,false);
});
test('Private reports and invalid destinations never generate a GitHub link', () => {
    for(const category of ['abuse','content','appeal','unknown']) assert.equal(githubReport(repo,{...form,category},'https://world.test','Report'),null);
    for(const repository of ['https://evil.test/repo','owner/repo/../../issues','owner/repo?token=foo','']) assert.equal(githubReport(repository,form,'https://world.test','Report'),null);
});
test('Long multibyte reports are copied in full instead of silently truncated or producing oversized URLs', () => {
    const body='界'.repeat(5000);
    const issue=githubReport(repo,{...form,body},'https://world.test','Bug');
    assert.ok(issue.needsCopy);assert.ok(issue.url.length<7000);
    assert.equal(new URL(issue.url).searchParams.get('body'),null);
    assert.ok(issue.text.startsWith(body));
});
test('Sign-in drafts are tab-local, scope-specific, expire and are consumed once', () => {
    const values=new Map();const storage={setItem:(k,v)=>values.set(k,v),getItem:k=>values.get(k),removeItem:k=>values.delete(k)};
    assert.ok(saveReportDraft(storage,form,100));
    assert.equal(takeReportDraft(storage,'/other',['bug'],101),null);
    assert.deepEqual(takeReportDraft(storage,form.ref,['bug'],101),form);
    assert.equal(takeReportDraft(storage,form.ref,['bug'],102),null);
    saveReportDraft(storage,form,100);
    assert.equal(takeReportDraft(storage,form.ref,['bug'],100+31*60*1000),null);
    assert.equal(values.size,0);
    saveReportDraft(storage,form);clearReportDraft(storage);assert.equal(values.size,0);
    assert.equal(saveReportDraft({setItem(){throw Error('blocked')}},form),false);
});
