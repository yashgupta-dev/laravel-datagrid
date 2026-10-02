/**
 * @codecorner/datagrid-core
 * State + API client. No DOM, no framework. Renderers subscribe to it.
 */

/** Turn the flat filter list the UI edits into the server's filter tree. */
export function buildFilterTree(filters) {
  const active = filters.filter((f) => f.field && f.op);
  return active.length
    ? { logic: 'and', children: active.map(({ field, op, value }) => ({ field, op, value })) }
    : null;
}

export const NO_VALUE_OPERATORS = ['is_null', 'not_null'];

export function createGrid(options) {
  const base = options.url.replace(/\/$/, '');
  const doFetch = options.fetch || ((...a) => fetch(...a));
  const state = {
    schema: null,
    rows: [],
    meta: null,
    loading: false,
    error: null,
    page: 1,
    perPage: options.perPage || 25,
    sort: [],
    search: '',
    filters: [],
    hidden: [],
  };
  const subscribers = new Set();
  const pageCache = new Map();
  const pageTtl = 30000;
  let seq = 0;

  function cacheKey() {
    return JSON.stringify({
      page: state.page,
      perPage: state.perPage,
      search: state.search,
      sort: state.sort,
      columns: visibleColumns().map((column) => column.key),
      filters: state.filters,
    });
  }

  function rememberPage(key, result) {
    pageCache.set(key, { at: Date.now(), rows: result.data, meta: result.meta });
    if (pageCache.size > 20) pageCache.delete(pageCache.keys().next().value);
  }

  function invalidate() {
    pageCache.clear();
  }

  const emit = () => subscribers.forEach((fn) => fn(state));

  async function request(path, init = {}) {
    const isForm = typeof FormData !== 'undefined' && init.body instanceof FormData;
    const headers = {
      Accept: 'application/json',
      ...(init.body && !isForm ? { 'Content-Type': 'application/json' } : {}),
      ...(options.csrf ? { 'X-CSRF-TOKEN': options.csrf } : {}),
      ...(options.headers || {}),
      ...(init.headers || {}),
    };
    const res = await doFetch(base + path, {
      credentials: options.credentials || 'same-origin',
      ...init,
      headers,
    });
    if (!res.ok) {
      let message = `Request failed (${res.status})`;
      try {
        const body = await res.json();
        if (body && body.message) message = body.message;
      } catch (_) { /* non-JSON error body */ }
      const error = new Error(message);
      error.status = res.status;
      throw error;
    }
    if (res.status === 204) return null;
    const text = await res.text();
    return text ? JSON.parse(text) : null;
  }

  const visibleColumns = () =>
    state.schema ? state.schema.columns.filter((c) => !state.hidden.includes(c.key)) : [];

  async function load(options = {}) {
    const mine = ++seq; // ignore out-of-order responses
    if (options.fresh) pageCache.clear();
    const key = cacheKey();
    const hit = !options.fresh && pageCache.get(key);
    if (hit && Date.now() - hit.at < pageTtl) {
      state.rows = hit.rows;
      state.meta = hit.meta;
      state.loading = false;
      state.error = null;
      emit();
      return;
    }
    state.loading = true;
    state.error = null;
    emit();
    try {
      const payload = {
        page: state.page,
        perPage: state.perPage,
        search: state.search,
        sort: state.sort,
        columns: visibleColumns().map((c) => c.key),
        filter: buildFilterTree(state.filters),
      };
      const result = await request('/data', { method: 'POST', body: JSON.stringify(payload) });
      if (mine !== seq) return;
      state.rows = result.data;
      state.meta = result.meta;
      rememberPage(key, result);
    } catch (e) {
      if (mine !== seq) return;
      state.error = e.message;
    } finally {
      if (mine === seq) {
        state.loading = false;
        emit();
      }
    }
  }

  const grid = {
    state,
    subscribe(fn) {
      subscribers.add(fn);
      return () => subscribers.delete(fn);
    },
    async init() {
      try {
        state.schema = await request('/schema');
        state.hidden = state.schema.columns.filter((c) => !c.visible).map((c) => c.key);
      } catch (e) {
        state.error = e.message;
        emit();
        return;
      }
      await load();
    },
    load,
    invalidate,
    visibleColumns,
    setSearch(term) { state.search = term; state.page = 1; return load(); },
    setPage(page) { state.page = Math.max(1, page); return load(); },
    setPerPage(n) { state.perPage = n; state.page = 1; return load(); },
    /** none -> asc -> desc -> none. `additive` keeps other sorts (multi-sort). */
    toggleSort(field, additive = false) {
      const current = state.sort.find((s) => s.field === field);
      const others = additive ? state.sort.filter((s) => s.field !== field) : [];
      if (!current) state.sort = [...others, { field, dir: 'asc' }];
      else if (current.dir === 'asc') state.sort = [...others, { field, dir: 'desc' }];
      else state.sort = others;
      state.page = 1;
      return load();
    },
    /** Replace the sort list. Each item is { field, dir }. */
    setSort(sort) {
      state.sort = sort;
      state.page = 1;
      return load();
    },
    setFilters(filters) { state.filters = filters; state.page = 1; return load(); },
    /** Replace search and filters, then reload once. */
    applyQuery({ search, filters }) {
      if (search !== undefined) state.search = search;
      if (filters !== undefined) state.filters = filters;
      state.page = 1;
      return load();
    },
    toggleColumn(key) {
      state.hidden = state.hidden.includes(key)
        ? state.hidden.filter((k) => k !== key)
        : [...state.hidden, key];
      return load();
    },
    /** POST a row or bulk action. Pass `{ bulk: true }` to queue a bulk action. */
    async runAction(key, ids, extra = {}) {
      const result = await request(`/actions/${encodeURIComponent(key)}`, {
        method: 'POST',
        body: JSON.stringify({ ids, ...extra }),
      });
      invalidate();
      return result;
    },
    pollJob(id, token) {
      return request(`/jobs/${encodeURIComponent(id)}`, {
        headers: { 'X-Datagrid-Token': token || '' },
      });
    },
    exportRows(payload) {
      return request('/export', { method: 'POST', body: JSON.stringify(payload) });
    },
    importFile(file, mapping) {
      const body = new FormData();
      body.append('file', file);
      body.append('mapping', JSON.stringify(mapping || {}));
      return request('/import', { method: 'POST', body });
    },
    async patchCell(id, field, value) {
      const result = await request(`/rows/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        body: JSON.stringify({ field, value }),
      });
      invalidate();
      return result;
    },
    listFilters() { return request('/filters'); },
    saveFilter(payload) {
      return request('/filters', { method: 'POST', body: JSON.stringify(payload) });
    },
    updateFilter(id, payload) {
      return request(`/filters/${encodeURIComponent(id)}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      });
    },
    deleteFilter(id) {
      return request(`/filters/${encodeURIComponent(id)}`, { method: 'DELETE' });
    },
  };
  return grid;
}
