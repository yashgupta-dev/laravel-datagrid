import assert from 'node:assert/strict';
import { createGrid, buildFilterTree } from './index.js';

const schema = { schemaVersion: 1, columns: [
  { key: 'name', visible: true, sortable: true },
  { key: 'email', visible: true },
  { key: 'secret', visible: false },
]};
const calls = [];
const fakeFetch = async (url, init) => {
  calls.push({ url, body: init.body ? JSON.parse(init.body) : null, headers: init.headers });
  if (url.endsWith('/schema')) return { ok: true, json: async () => schema };
  return { ok: true, json: async () => ({ data: [{ id: 1, values: { name: 'A' } }], meta: { page: 1, total: 1, hasMore: false } }) };
};

const grid = createGrid({ url: 'http://x/datagrid/users/', fetch: fakeFetch, csrf: 'tok' });
await grid.init();
assert.equal(calls[0].url, 'http://x/datagrid/users/schema');
assert.deepEqual(calls[1].body.columns, ['name', 'email'], 'default-hidden columns are not requested');
assert.equal(calls[1].headers['X-CSRF-TOKEN'], 'tok');

await grid.toggleSort('name');
assert.deepEqual(grid.state.sort, [{ field: 'name', dir: 'asc' }]);
await grid.toggleSort('name');
assert.equal(grid.state.sort[0].dir, 'desc');
await grid.toggleSort('name');
assert.deepEqual(grid.state.sort, []);

await grid.setPage(3);
await grid.setSearch('ali');
assert.equal(grid.state.page, 1, 'search resets page');
assert.equal(calls.at(-1).body.search, 'ali');

await grid.toggleColumn('secret');
assert.ok(calls.at(-1).body.columns.includes('secret'));

assert.equal(buildFilterTree([]), null);
assert.deepEqual(buildFilterTree([{ field: 'a', op: 'eq', value: 1 }, { field: '', op: 'eq' }]),
  { logic: 'and', children: [{ field: 'a', op: 'eq', value: 1 }] });

// stale responses are ignored
let release;
const slow = createGrid({ url: 'u', fetch: async (url, init) => {
  if (url.endsWith('/schema')) return { ok: true, json: async () => schema };
  const q = JSON.parse(init.body).search;
  if (q === 'old') await new Promise((r) => (release = r));
  return { ok: true, json: async () => ({ data: [{ id: q, values: {} }], meta: {} }) };
} });
await slow.init();
const first = slow.setSearch('old');
await slow.setSearch('new');
release(); await first;
assert.equal(slow.state.rows[0].id, 'new', 'late old response must not overwrite newer one');

// errors surface
const bad = createGrid({ url: 'u', fetch: async () => ({ ok: false, status: 422, json: async () => ({ message: 'Bad filter' }) }) });
await bad.init();
assert.equal(bad.state.error, 'Bad filter');

console.log('core tests passed');
