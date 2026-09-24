const test = require('node:test');
const assert = require('node:assert');
const { createClient } = require('../../js/photo-upload.js');

function makeClient(response) {
    const calls = [];
    const fetchFn = async (url, init) => { calls.push({ url, init }); return response; };
    const resizeFn = async (file) => new Blob(['resized:' + file.name]);
    return { calls, client: createClient({ fetchFn, resizeFn, csrfToken: 'tok123' }) };
}

const okResponse = (body) => ({ ok: true, json: async () => body });

test('upload resizes, posts the expected fields, and resolves the photo', async () => {
    const photo = { id: 9, requirement_key: 'harness_date', typed: { date: '05/2025' } };
    const { calls, client } = makeClient(okResponse({ ok: true, photo }));

    const result = await client.upload({
        file: { name: 'big.heic' }, subjectType: 'tech_sheet', subjectId: 7,
        requirementKey: 'harness_date', typed: { date: '05/2025' },
    });

    assert.deepStrictEqual(result, photo);
    assert.strictEqual(calls.length, 1);
    assert.strictEqual(calls[0].url, 'inspection.php?action=upload');
    assert.strictEqual(calls[0].init.method, 'POST');
    const body = calls[0].init.body;
    assert.strictEqual(body.get('csrf_token'), 'tok123');
    assert.strictEqual(body.get('subject_type'), 'tech_sheet');
    assert.strictEqual(body.get('subject_id'), '7');
    assert.strictEqual(body.get('requirement_key'), 'harness_date');
    assert.strictEqual(body.get('typed[date]'), '05/2025');
    const sent = body.get('photo');
    assert.strictEqual(sent.name, 'harness_date.jpg');
    assert.strictEqual(await sent.text(), 'resized:big.heic');
});

test('upload rejects with the server message', async () => {
    const { client } = makeClient({ ok: false, json: async () => ({ ok: false, error: 'The photo is too large (2 MB maximum).' }) });
    await assert.rejects(
        client.upload({ file: { name: 'x.jpg' }, subjectType: 'tech_sheet', subjectId: 1, requirementKey: 'front_34' }),
        { message: 'The photo is too large (2 MB maximum).' }
    );
});

test('upload rejects with a generic message on an unreadable response', async () => {
    const { client } = makeClient({ ok: false, json: async () => { throw new Error('bad json'); } });
    await assert.rejects(
        client.upload({ file: { name: 'x.jpg' }, subjectType: 'tech_sheet', subjectId: 1, requirementKey: 'front_34' }),
        { message: 'Upload failed. Please try again.' }
    );
});

test('remove posts the id and csrf token', async () => {
    const { calls, client } = makeClient(okResponse({ ok: true }));
    await client.remove({ id: 9 });
    assert.strictEqual(calls[0].url, 'inspection.php?action=delete');
    assert.strictEqual(calls[0].init.body.get('id'), '9');
    assert.strictEqual(calls[0].init.body.get('csrf_token'), 'tok123');
});
