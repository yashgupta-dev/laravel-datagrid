import { buildFilterTree, createGrid, NO_VALUE_OPERATORS } from './datagrid-core.js';

/* Cell renderer registry: register your own types. fn(value, row, column) => Node|string */
const cells = {
  boolean: (v) => (v === null || v === undefined ? '' : v ? '✓' : '✗'),
  date: (v) => (v ? new Date(v).toLocaleDateString() : ''),
  datetime: (v) => (v ? new Date(v).toLocaleString() : ''),
  number: (v) => (v === null || v === undefined ? '' : Number(v).toLocaleString()),
  money: (v) => (v === null || v === undefined ? '' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 })),
  password: (v) => (v === null || v === undefined || v === '' ? '' : '••••••••'),
  hidden: (v) => (v === null || v === undefined || v === '' ? '' : '••••••••'),
  image: (v, row, column) => {
    const src = safeUrl(v);
    if (!src) return '';
    const size = column.image || {};
    const img = document.createElement('img');
    img.className = 'dg-image';
    img.src = src;
    img.alt = '';
    img.width = Number(size.width) || 40;
    img.height = Number(size.height) || 40;
    img.loading = 'lazy';
    img.decoding = 'async';
    img.referrerPolicy = 'no-referrer';
    return img;
  },
  link: (v) => {
    const href = safeUrl(v);
    if (!href) return v === null || v === undefined || v === '' ? '' : String(v);
    const link = document.createElement('a');
    link.className = 'dg-anchor';
    link.href = href;
    link.textContent = String(v);
    link.rel = 'noopener noreferrer';
    if (href.startsWith('http')) link.target = '_blank';
    return link;
  },
  badge: (v) => {
    if (v === null || v === undefined) return '';
    const s = document.createElement('span');
    s.className = 'dg-badge';
    s.textContent = String(v);
    return s;
  },
};
export const registerCell = (type, fn) => { cells[type] = fn; };

function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (k === 'class') node.className = v;
    else if (k === 'style') Object.assign(node.style, v);
    else if (k.startsWith('on')) node.addEventListener(k.slice(2).toLowerCase(), v);
    else if (v !== undefined && v !== null && v !== false) node.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat()) {
    if (c === null || c === undefined || c === false) continue;
    node.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return node;
}

function nodes(...children) {
  return children.flat().filter((child) => child instanceof Node);
}

function safeUrl(value) {
  const raw = String(value ?? '').trim();
  if (raw.startsWith('/') && !raw.startsWith('//') && !raw.startsWith('/\\')) return raw;
  try {
    const url = new URL(raw);
    if (url.protocol === 'https:' || url.protocol === 'http:') return url.href;
  } catch (_) { /* not a url */ }
  return null;
}

function renderValue(row, column) {
  const value = row.values[column.key];
  const paint = cells[column.type] || ((x) => (x === null || x === undefined ? '' : typeof x === 'object' ? JSON.stringify(x) : x));
  return paint(value, row, column);
}

function textOf(node) {
  if (node === null || node === undefined || node === false) return '';
  return node instanceof Node ? node.textContent : String(node);
}

function icon(name) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('width', '18');
  svg.setAttribute('height', '18');
  svg.setAttribute('aria-hidden', 'true');
  if (name === 'dots') {
    svg.setAttribute('fill', 'currentColor');
    [5, 12, 19].forEach((cy) => {
      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      circle.setAttribute('cx', '12');
      circle.setAttribute('cy', String(cy));
      circle.setAttribute('r', '1.7');
      svg.append(circle);
    });
    return svg;
  }
  svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor');
  svg.setAttribute('stroke-width', '1.8');
  svg.setAttribute('stroke-linecap', 'round');
  svg.setAttribute('stroke-linejoin', 'round');
  const paths = {
    check: 'M5 12.5 9.5 17 19 7',
    plus: 'M12 5v14M5 12h14',
    pencil: 'M4 20h4l10.5-10.5-4-4L4 16zM13 7l4 4',
    trash: 'M4 7h16M9 7V5h6v2M7 7l1 12h8l1-12',
    mail: 'M4 6h16v12H4zM4 7l8 6 8-6',
    copy: 'M9 9.5h9.5V19H9zM6.5 15.5V5.5H15',
    close: 'M6 6 18 18M18 6 6 18',
    alert: 'M12 8v5M12 16.5h.01',
    search: 'M16.2 16.2 20.5 20.5',
    empty: 'M4 8h16v10.5H4zM4 8l2.2-3h11.6L20 8M9 13.5h6',
    chevron: 'M6 9l6 6 6-6',
  };
  if (name === 'alert' || name === 'search') {
    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    circle.setAttribute('cx', name === 'search' ? '11' : '12');
    circle.setAttribute('cy', name === 'search' ? '11' : '12');
    circle.setAttribute('r', name === 'search' ? '6.5' : '8');
    svg.append(circle);
  }
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', paths[name] || paths.copy);
  svg.append(path);
  return svg;
}

export function mountDatagrid(root, options) {
  const grid = createGrid(options);
  const prefKey = `dg-layout:${options.url || ''}`;
  const ui = {
    popup: null,
    menu: null,
    draft: [],
    selected: new Set(),
    notice: '',
    busy: false,
    confirm: null,
    jobs: {},
    editing: null,
    saved: [],
    savedReady: false,
    savedAuth: true,
    filterName: '',
    editingSaved: null,
    pins: {},
    widths: {},
    order: [],
    prefsApplied: false,
    prefsNeedReload: false,
    importHeaders: [],
    importMap: {},
    importFile: null,
    importName: '',
    errorDismissed: '',
    toastSig: '',
    compact: window.matchMedia('(max-width: 768px)').matches,
  };
  root.classList.add('dg');
  const compactQuery = window.matchMedia('(max-width: 768px)');
  compactQuery.addEventListener('change', () => {
    ui.compact = compactQuery.matches;
    if (!ui.compact && ui.menu && ui.menu.key === '__tools') ui.menu = null;
    render();
  });

  let searchTimer;
  const searchInput = el('input', {
    class: 'dg-search',
    type: 'search',
    placeholder: options.searchPlaceholder || 'Search…',
    onInput: (e) => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(() => grid.setSearch(e.target.value), 300);
    },
  });

  const body = el('div');
  const toastHost = el('div', { class: 'dg-toast-stack', role: 'status', 'aria-live': 'polite' });
  const dismissTimers = {};
  root.replaceChildren(body, toastHost);

  function readPrefs() {
    try {
      return JSON.parse(localStorage.getItem(prefKey) || 'null');
    } catch (_) {
      return null;
    }
  }

  function savePrefs() {
    const payload = { hidden: grid.state.hidden, pins: ui.pins, widths: ui.widths, order: ui.order };
    try { localStorage.setItem(prefKey, JSON.stringify(payload)); } catch (_) { /* private mode */ }
  }

  function ensurePrefs() {
    const schema = grid.state.schema;
    if (ui.prefsApplied || !schema) return;
    ui.prefsApplied = true;
    schema.columns.forEach((column) => {
      if (column.pinned === 'left' || column.pinned === 'right') ui.pins[column.key] = column.pinned;
      if (column.width) ui.widths[column.key] = column.width;
    });
    const saved = readPrefs();
    const defaults = schema.columns.filter((column) => !column.visible).map((column) => column.key);
    if (!saved) return;
    if (Array.isArray(saved.hidden)) {
      grid.state.hidden = saved.hidden.filter((key) => schema.columns.some((column) => column.key === key && column.toggleable !== false));
    }
    if (saved.pins && typeof saved.pins === 'object') ui.pins = saved.pins;
    if (saved.widths && typeof saved.widths === 'object') ui.widths = saved.widths;
    if (Array.isArray(saved.order)) {
      ui.order = saved.order.filter((key) => schema.columns.some((column) => column.key === key));
    }
    ui.prefsNeedReload = JSON.stringify([...grid.state.hidden].sort()) !== JSON.stringify([...defaults].sort());
  }

  function columnKeys() {
    const schema = (grid.state.schema?.columns || []).map((column) => column.key);
    const saved = (ui.order || []).filter((key) => schema.includes(key));
    return [...saved, ...schema.filter((key) => !saved.includes(key))];
  }

  function pinZone(column) {
    if (ui.pins[column.key] === 'left') return 'left';
    if (ui.pins[column.key] === 'right') return 'right';
    return 'center';
  }

  function arrangedColumns(columns) {
    const keys = columnKeys();
    const zoneRank = { left: 0, center: 1, right: 2 };
    return [...columns].sort((a, b) => zoneRank[pinZone(a)] - zoneRank[pinZone(b)] || keys.indexOf(a.key) - keys.indexOf(b.key));
  }

  function orderedColumns() {
    return arrangedColumns(grid.visibleColumns());
  }

  function moveColumn(key, direction) {
    const columns = arrangedColumns(grid.state.schema.columns);
    const index = columns.findIndex((column) => column.key === key);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= columns.length) return;
    const current = columns[index];
    const neighbor = columns[target];
    const neighborZone = pinZone(neighbor);
    if (pinZone(current) !== neighborZone) {
      if (neighborZone === 'center') delete ui.pins[current.key];
      else ui.pins[current.key] = neighborZone;
    }
    const keys = columnKeys();
    const from = keys.indexOf(current.key);
    const to = keys.indexOf(neighbor.key);
    keys.splice(from, 1);
    keys.splice(to, 0, current.key);
    ui.order = keys;
    savePrefs();
    render();
  }

  function columnOrderRows() {
    const columns = arrangedColumns(grid.state.schema.columns);
    return columns.map((column, index) => el('div', { class: 'dg-col-row' },
      el('label', {},
        el('input', {
          type: 'checkbox',
          checked: !grid.state.hidden.includes(column.key),
          disabled: column.toggleable === false,
          onChange: async (e) => {
            e.stopPropagation();
            await grid.toggleColumn(column.key);
            savePrefs();
          },
        }),
        column.label),
      el('button', {
        type: 'button',
        class: 'dg-col-move',
        'aria-label': `Move ${column.label} earlier`,
        disabled: index === 0,
        onClick: (e) => { e.stopPropagation(); moveColumn(column.key, -1); },
      }, '↑'),
      el('button', {
        type: 'button',
        class: 'dg-col-move',
        'aria-label': `Move ${column.label} later`,
        disabled: index === columns.length - 1,
        onClick: (e) => { e.stopPropagation(); moveColumn(column.key, 1); },
      }, '↓')));
  }

  function widthOf(column) {
    return ui.widths[column.key] || column.width || 180;
  }

  function filterableColumns() {
    return (grid.state.schema?.columns || []).filter((column) => column.filter);
  }

  function filterPanel() {
    const filterable = filterableColumns();
    if (!filterable.length) return el('p', { class: 'dg-muted' }, 'No filterable columns.');
    const rows = ui.draft.map((f, i) => {
      const col = filterable.find((c) => c.key === f.field) || filterable[0];
      const ops = col.filter.operators;
      const set = (patch) => { ui.draft[i] = { ...ui.draft[i], ...patch }; render(); };
      const fieldSel = el('select', { onChange: (e) => {
        const next = filterable.find((c) => c.key === e.target.value);
        set({ field: next.key, op: next.filter.defaultOperator, value: '' });
      } }, filterable.map((c) => el('option', { value: c.key, selected: c.key === col.key }, c.label)));
      const opSel = el('select', { onChange: (e) => set({ op: e.target.value, value: '' }) },
        ops.map((o) => el('option', { value: o, selected: o === f.op }, o.replaceAll('_', ' '))));
      let input = null;
      if (!NO_VALUE_OPERATORS.includes(f.op)) {
        const opts = (col.filter.options && col.filter.options.length) ? col.filter.options : (col.options || []);
        const list = f.op === 'in' || f.op === 'not_in';
        if (opts && opts.length) {
          input = el('select', { multiple: list, onChange: (e) => {
            const picked = [...e.target.selectedOptions].map((o) => o.value);
            ui.draft[i].value = list ? picked : picked[0];
          } }, !list && el('option', { value: '' }, '—'),
            opts.map((o) => el('option', { value: o.value, selected: list ? (f.value || []).includes(String(o.value)) : String(f.value) === String(o.value) }, o.label)));
        } else if (f.op === 'between') {
          const v = Array.isArray(f.value) ? f.value : ['', ''];
          input = el('span', {},
            el('input', { value: v[0], onInput: (e) => { ui.draft[i].value = [e.target.value, (ui.draft[i].value || [])[1] ?? '']; } }),
            ' – ',
            el('input', { value: v[1], onInput: (e) => { ui.draft[i].value = [(ui.draft[i].value || [])[0] ?? '', e.target.value]; } }));
        } else if (list) {
          input = el('input', { placeholder: 'a, b, c', value: Array.isArray(f.value) ? f.value.join(', ') : '', onInput: (e) => {
            ui.draft[i].value = e.target.value.split(',').map((s) => s.trim()).filter(Boolean);
          } });
        } else {
          input = el('input', { type: col.filter.type === 'date' ? 'date' : col.filter.type === 'number' ? 'number' : 'text', value: f.value ?? '', onInput: (e) => { ui.draft[i].value = e.target.value; } });
        }
      }
      return el('div', { class: 'dg-row' }, fieldSel, opSel, input,
        el('button', { type: 'button', onClick: () => { ui.draft.splice(i, 1); render(); } }, 'Remove'));
    });
    return el('div', {}, rows);
  }

  function openFilter(field) {
    const filterable = filterableColumns();
    if (!filterable.length) return;
    ui.draft = grid.state.filters.map((f) => ({ ...f }));
    if (field && !ui.draft.some((f) => f.field === field)) {
      const col = filterable.find((c) => c.key === field) || filterable[0];
      ui.draft.push({ field: col.key, op: col.filter.defaultOperator, value: '' });
    }
    if (!ui.draft.length) {
      const col = filterable.find((c) => c.key === field) || filterable[0];
      ui.draft.push({ field: col.key, op: col.filter.defaultOperator, value: '' });
    }
    ui.popup = 'filter';
    ui.menu = null;
    render();
    if (!ui.savedReady) refreshSaved();
  }

  function closePopup() {
    ui.popup = null;
    ui.confirm = null;
    render();
  }

  function ring(done, total) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'dg-ring');
    svg.setAttribute('viewBox', '0 0 16 16');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('aria-hidden', 'true');
    const radius = 6;
    const circ = 2 * Math.PI * radius;
    const pct = total > 0 ? Math.min(1, done / total) : 0;
    const circle = (name, offset) => {
      const node = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      node.setAttribute('class', name);
      node.setAttribute('cx', '8');
      node.setAttribute('cy', '8');
      node.setAttribute('r', String(radius));
      if (offset !== undefined) {
        node.setAttribute('stroke-dasharray', String(circ));
        node.setAttribute('stroke-dashoffset', String(offset));
      }
      return node;
    };
    svg.append(circle('dg-ring-bg'), circle('dg-ring-fg', circ * (1 - pct)));
    return svg;
  }

  function jobActive(slot) {
    const job = ui.jobs[slot];
    return job && job.status !== 'done' && job.status !== 'failed';
  }

  function dialog(title, content, footer) {
    return el('div', { class: 'dg-backdrop', onClick: () => closePopup() },
      el('div', { class: 'dg-dialog', role: 'dialog', onClick: (e) => e.stopPropagation() },
        el('div', { class: 'dg-dialog-h' },
          el('strong', {}, title),
          el('button', { type: 'button', class: 'dg-icon', 'aria-label': 'Close', onClick: () => closePopup() }, '✕')),
        el('div', { class: 'dg-dialog-b' }, content),
        footer));
  }

  function columnDialog() {
    const list = el('div', { class: 'dg-col-list' }, columnOrderRows());
    return dialog('Columns', list, el('div', { class: 'dg-dialog-f' },
      el('button', { type: 'button', onClick: () => resetColumns() }, 'Reset columns'),
      el('button', { type: 'button', class: 'dg-on', onClick: () => { ui.popup = null; render(); } }, 'Done')));
  }

  function sortDialog() {
    const sortable = grid.state.schema.columns.filter((column) => column.sortable && !grid.state.hidden.includes(column.key));
    const rows = grid.state.sort.map((sort, index) => el('div', { class: 'dg-row' },
      el('span', {}, sortable.find((column) => column.key === sort.field)?.label || sort.field),
      el('button', { type: 'button', onClick: () => grid.setSort(grid.state.sort.map((item, i) => i === index ? { ...item, dir: item.dir === 'asc' ? 'desc' : 'asc' } : item)) }, sort.dir === 'asc' ? 'Ascending' : 'Descending'),
      el('button', { type: 'button', onClick: () => grid.setSort(grid.state.sort.filter((_, i) => i !== index)) }, 'Remove')));
    return dialog('Sort', el('div', {},
      rows.length ? rows : el('p', { class: 'dg-muted' }, 'No sort applied.'),
      sortable.length ? el('div', { class: 'dg-row' },
        el('select', { id: 'dg-add-sort' }, sortable.map((column) => el('option', { value: column.key }, column.label))),
        el('button', { type: 'button', onClick: () => {
          const field = body.querySelector('#dg-add-sort')?.value;
          if (!field || grid.state.sort.some((sort) => sort.field === field)) return;
          grid.setSort([...grid.state.sort, { field, dir: 'asc' }]);
        } }, 'Add sort')) : null),
    el('div', { class: 'dg-dialog-f' },
      el('button', { type: 'button', onClick: () => grid.setSort([]) }, 'Clear'),
      el('button', { type: 'button', class: 'dg-on', onClick: () => { ui.popup = null; render(); } }, 'Done')));
  }

  const savedKey = `dg-filters:${options.url || ''}`;

  function readLocalSaved() {
    try { return JSON.parse(localStorage.getItem(savedKey) || '[]'); } catch (_) { return []; }
  }

  function writeLocalSaved(list) {
    try { localStorage.setItem(savedKey, JSON.stringify(list)); } catch (_) { /* private mode */ }
  }

  function savedFilterPanel() {
    const rows = ui.saved.map((item) => {
      const editing = ui.editingSaved === item.id;
      return el('div', { class: 'dg-saved-row' },
        editing
          ? el('input', { value: ui.filterName, onInput: (e) => { ui.filterName = e.target.value; } })
          : el('span', {}, item.name),
        el('button', { type: 'button', onClick: () => {
          ui.popup = null;
          ui.editingSaved = null;
          searchInput.value = item.search || '';
          grid.applyQuery({ search: item.search || '', filters: (item.filters || []).map((f) => ({ ...f })) });
        } }, 'Apply'),
        editing
          ? el('button', { type: 'button', onClick: () => updateSaved(item) }, 'Save')
          : el('button', { type: 'button', onClick: () => {
            ui.editingSaved = item.id;
            ui.filterName = item.name;
            ui.draft = (item.filters || []).map((f) => ({ ...f }));
            ui.popup = 'filter';
            render();
          } }, 'Edit'),
        el('button', { type: 'button', class: 'dg-danger', onClick: () => removeSaved(item) }, 'Remove'));
    });
    return el('div', { class: 'dg-saved' },
      rows.length ? rows : el('p', { class: 'dg-muted' }, 'No saved filters yet.'));
  }

  function saveFilterBar() {
    return el('div', { class: 'dg-save-bar' },
      el('input', { placeholder: 'Name this filter', value: ui.filterName, onInput: (e) => { ui.filterName = e.target.value; } }),
      el('button', { type: 'button', onClick: () => {
        const current = ui.saved.find((item) => item.id === ui.editingSaved);
        if (current) updateSaved(current);
        else addSaved();
      } }, ui.editingSaved ? 'Update filter' : 'Save filter'));
  }

  function openSaved() {
    ui.popup = 'saved';
    ui.menu = null;
    render();
    if (!ui.savedReady) refreshSaved();
  }

  function savedDialog() {
    return dialog('Saved filters', savedFilterPanel(), el('div', { class: 'dg-dialog-f' },
      el('button', { type: 'button', class: 'dg-on', onClick: () => closePopup() }, 'Done')));
  }

  async function refreshSaved() {
    try {
      const result = await grid.listFilters();
      if (result?.persisted === false) {
        ui.saved = readLocalSaved();
        ui.savedLocal = true;
      } else {
        ui.saved = result?.data || [];
        ui.savedLocal = false;
      }
    } catch (_) {
      ui.saved = readLocalSaved();
      ui.savedLocal = true;
    }
    ui.savedReady = true;
    render();
  }

  function currentSavedPayload() {
    return { filters: ui.draft.map((f) => ({ ...f })), search: grid.state.search };
  }

  async function addSaved() {
    const name = ui.filterName.trim();
    if (!name) return;
    if (ui.savedLocal) {
      ui.saved = [...ui.saved, { id: `local-${Date.now()}`, name, ...currentSavedPayload() }];
      writeLocalSaved(ui.saved);
      ui.filterName = '';
      toast('Filter saved.', 'ok');
      return;
    }
    try {
      await grid.saveFilter({ name, ...currentSavedPayload() });
      ui.filterName = '';
      await refreshSaved();
      toast('Filter saved.', 'ok');
    } catch (e) {
      toast(e.message, 'err');
    }
  }

  async function updateSaved(item) {
    const name = ui.filterName.trim();
    if (!name) return;
    if (ui.savedLocal) {
      ui.saved = ui.saved.map((saved) => saved.id === item.id ? { ...saved, name, ...currentSavedPayload() } : saved);
      writeLocalSaved(ui.saved);
      ui.editingSaved = null;
      ui.filterName = '';
      toast('Filter updated.', 'ok');
      return;
    }
    try {
      await grid.updateFilter(item.id, { name, ...currentSavedPayload() });
      ui.editingSaved = null;
      ui.filterName = '';
      await refreshSaved();
      toast('Filter updated.', 'ok');
    } catch (e) {
      toast(e.message, 'err');
    }
  }

  async function removeSaved(item) {
    if (ui.savedLocal) {
      ui.saved = ui.saved.filter((saved) => saved.id !== item.id);
      writeLocalSaved(ui.saved);
      if (ui.editingSaved === item.id) ui.editingSaved = null;
      toast('Filter removed.', 'ok');
      return;
    }
    try {
      await grid.deleteFilter(item.id);
      if (ui.editingSaved === item.id) ui.editingSaved = null;
      await refreshSaved();
      toast('Filter removed.', 'ok');
    } catch (e) {
      toast(e.message, 'err');
    }
  }

  function filterDialog() {
    return dialog('Filters', filterPanel(), el('div', { class: 'dg-dialog-f dg-dialog-f-stack' },
      saveFilterBar(),
      el('div', { class: 'dg-dialog-actions' },
        el('button', { type: 'button', onClick: () => {
          const column = filterableColumns()[0];
          if (!column) return;
          ui.draft.push({ field: column.key, op: column.filter.defaultOperator, value: '' });
          render();
        } }, 'Add filter'),
        el('button', { type: 'button', onClick: () => { ui.draft = []; ui.popup = null; grid.setFilters([]); } }, 'Clear'),
        el('button', { type: 'button', class: 'dg-on', onClick: () => { ui.popup = null; grid.setFilters(ui.draft.map((f) => ({ ...f }))); } }, 'Apply'))));
  }

  async function resetColumns() {
    const schema = grid.state.schema;
    grid.state.hidden = schema.columns.filter((column) => !column.visible).map((column) => column.key);
    ui.pins = {};
    ui.widths = {};
    ui.order = [];
    schema.columns.forEach((column) => {
      if (column.pinned === 'left' || column.pinned === 'right') ui.pins[column.key] = column.pinned;
      if (column.width) ui.widths[column.key] = column.width;
    });
    try { localStorage.removeItem(prefKey); } catch (_) { /* ignore */ }
    ui.popup = null;
    ui.menu = null;
    await grid.load();
  }

  function setPin(key, side) {
    if (side) ui.pins[key] = side;
    else delete ui.pins[key];
    ui.menu = null;
    savePrefs();
    render();
  }

  function autosize(column) {
    const header = column.label.length;
    const widest = grid.state.rows.reduce((max, row) => Math.max(max, textOf(renderValue(row, column)).length), header);
    ui.widths[column.key] = Math.min(480, Math.max(96, widest * 8 + 56));
    ui.menu = null;
    savePrefs();
    render();
  }

  function columnMenu(column) {
    if (!ui.menu || ui.menu.key !== column.key) return null;
    const item = (label, onClick, extra = {}) => el('button', { type: 'button', ...extra, onClick: (e) => { e.stopPropagation(); onClick(); } }, label);
    return el('div', { class: 'dg-menu', style: { top: `${ui.menu.top}px`, left: `${ui.menu.left}px` }, onClick: (e) => e.stopPropagation() },
      column.sortable && item('Sort ascending', () => { ui.menu = null; grid.setSort([{ field: column.key, dir: 'asc' }]); }),
      column.sortable && item('Sort descending', () => { ui.menu = null; grid.setSort([{ field: column.key, dir: 'desc' }]); }),
      column.filter && item('Filter', () => openFilter(column.key)),
      item('Autosize', () => autosize(column)),
      item('Pin left', () => setPin(column.key, 'left')),
      item('Pin right', () => setPin(column.key, 'right')),
      ui.pins[column.key] && item('Unpin', () => setPin(column.key, null)),
      column.toggleable !== false && item('Hide column', async () => {
        ui.menu = null;
        if (!grid.state.hidden.includes(column.key)) await grid.toggleColumn(column.key);
        savePrefs();
      }),
      item('Manage columns', () => { ui.menu = null; ui.popup = 'columns'; render(); }),
      item('Reset columns', () => resetColumns()));
  }

  function optionLabel(column, raw) {
    const match = (column.options || []).find((item) => item.value === String(raw ?? ''));
    if (match) return match.label;
    if (raw === null || raw === undefined || raw === '') return '';
    return String(raw);
  }

  function optionSummary(column, value) {
    if (!column.multiple) return optionLabel(column, value);
    const values = Array.isArray(value) ? value : [];
    return values.map((item) => optionLabel(column, item)).filter(Boolean).join(', ');
  }

  function openValueMenu(row, column) {
    const key = `__values:${row.id}:${column.key}`;
    if (ui.menu && ui.menu.key === key) {
      ui.menu = null;
      render();
      return;
    }
    const current = row.values[column.key];
    ui.menu = {
      key,
      kind: 'values',
      anchor: key,
      rowId: row.id,
      field: column.key,
      top: 0,
      left: 0,
      draft: column.multiple ? (Array.isArray(current) ? current.map(String) : []) : null,
    };
    ui.popup = null;
    render();
  }

  async function applyColumnValue(row, column, value) {
    const previous = row.values[column.key];
    const next = column.multiple ? [...(ui.menu?.draft || [])] : value;
    row.values[column.key] = next;
    ui.menu = null;
    render();
    try {
      const result = await grid.patchCell(row.id, column.key, next);
      const current = grid.state.rows.find((item) => item.id === row.id);
      if (current) current.values[column.key] = result.value;
      toast('Saved.', 'ok');
    } catch (e) {
      const current = grid.state.rows.find((item) => item.id === row.id);
      if (current) current.values[column.key] = previous;
      toast(e.message, 'err');
    }
  }

  function valueMenu() {
    if (!ui.menu || ui.menu.kind !== 'values') return null;
    const column = grid.state.schema.columns.find((item) => item.key === ui.menu.field);
    const row = grid.state.rows.find((item) => item.id === ui.menu.rowId);
    if (!column || !row) return null;
    const menu = el('div', {
      class: 'dg-menu',
      style: { top: `${ui.menu.top}px`, left: `${ui.menu.left}px` },
      onClick: (e) => e.stopPropagation(),
    });
    if (column.multiple) {
      menu.append(...column.options.map((option) => el('label', {},
        el('input', {
          type: 'checkbox',
          checked: ui.menu.draft.includes(option.value),
          onChange: (e) => {
            const next = new Set(ui.menu.draft);
            if (e.target.checked) next.add(option.value);
            else next.delete(option.value);
            ui.menu.draft = [...next];
          },
        }),
        option.label)));
      menu.append(el('button', { type: 'button', class: 'dg-on', onClick: (e) => { e.stopPropagation(); applyColumnValue(row, column); } }, 'Apply'));
      return menu;
    }
    menu.append(...column.options.map((option) => el('button', {
      type: 'button',
      class: String(row.values[column.key] ?? '') === option.value ? 'dg-on' : '',
      onClick: (e) => { e.stopPropagation(); applyColumnValue(row, column, option.value); },
    }, option.label)));
    return menu;
  }

  function openColumnMenu(column) {
    ui.menu = { key: column.key, anchor: `column:${column.key}`, align: 'end', top: 0, left: 0 };
    ui.popup = null;
    render();
  }

  function menuAnchor(id) {
    return [...body.querySelectorAll(`[data-dg-anchor="${CSS.escape(id)}"]`)]
      .find((node) => node.getClientRects().length > 0) || null;
  }

  function wrapBox() {
    const wrap = body.querySelector('.dg-wrap');
    if (!wrap) return null;
    const view = wrap.getBoundingClientRect();
    return { top: view.top, bottom: view.bottom, left: view.left, right: view.right };
  }

  function visibleGridBox() {
    const view = wrapBox();
    const wrap = body.querySelector('.dg-wrap');
    if (!view || !wrap) return null;
    let left = view.left;
    let right = view.right;
    wrap.querySelectorAll('th.dg-pin-left').forEach((cell) => {
      left = Math.max(left, cell.getBoundingClientRect().right);
    });
    wrap.querySelectorAll('th.dg-pin-right').forEach((cell) => {
      right = Math.min(right, cell.getBoundingClientRect().left);
    });
    return { top: view.top, bottom: view.bottom, left, right };
  }

  function pinnedAnchor(anchor) {
    return !!anchor.closest('.dg-pin-left, .dg-pin-right');
  }

  function intersects(rect, view) {
    return rect.bottom > view.top && rect.top < view.bottom && rect.right > view.left + 1 && rect.left < view.right - 1;
  }

  function anchorInView(anchor) {
    const rect = anchor.getBoundingClientRect();
    const wrap = body.querySelector('.dg-wrap');
    if (!wrap || !wrap.contains(anchor)) return true;
    return intersects(rect, pinnedAnchor(anchor) ? wrapBox() : visibleGridBox());
  }

  function placeOpenMenu() {
    if (!ui.menu?.anchor) return;
    const menu = body.querySelector(':scope > .dg-menu');
    const anchor = menuAnchor(ui.menu.anchor);
    if (!menu || !anchor) return;
    const visible = anchorInView(anchor);
    menu.hidden = !visible;
    if (!visible) return;
    const rect = anchor.getBoundingClientRect();
    const box = menu.getBoundingClientRect();
    const view = pinnedAnchor(anchor) ? wrapBox() : visibleGridBox();
    const margin = 8;
    const gap = 6;
    let top = rect.bottom + gap;
    if (top + box.height > window.innerHeight - margin && rect.top - gap - box.height >= margin) {
      top = rect.top - gap - box.height;
    }
    let left = ui.menu.align === 'end' ? rect.right - box.width : rect.left;
    if (view && body.querySelector('.dg-wrap')?.contains(anchor)) left = Math.max(left, view.left);
    left = Math.max(margin, Math.min(left, window.innerWidth - box.width - margin));
    if (view && left + box.width > view.right) left = Math.max(view.left, view.right - box.width);
    menu.style.top = `${Math.round(top)}px`;
    menu.style.left = `${Math.round(left)}px`;
  }

  function actionCap() {
    return grid.state.schema?.limits?.maxActionIds || 500;
  }

  function selectId(id, on) {
    if (on) {
      if (ui.selected.has(id)) return;
      if (ui.selected.size >= actionCap()) {
        grid.state.error = `You can act on at most ${actionCap()} rows at once.`;
        render();
        return;
      }
      ui.selected.add(id);
    } else ui.selected.delete(id);
    render();
  }

  function selectPage(on) {
    const pageIds = grid.state.rows.map((row) => row.id);
    if (!on) pageIds.forEach((id) => ui.selected.delete(id));
    else {
      for (const id of pageIds) {
        if (ui.selected.has(id)) continue;
        if (ui.selected.size >= actionCap()) {
          grid.state.error = `You can act on at most ${actionCap()} rows at once.`;
          break;
        }
        ui.selected.add(id);
      }
    }
    render();
  }

  function confirmDialog() {
    const pending = ui.confirm;
    if (!pending) return null;
    return dialog(pending.action.label, el('p', {}, pending.action.confirm), el('div', { class: 'dg-dialog-f' },
      el('button', { type: 'button', onClick: () => closePopup() }, 'Cancel'),
      el('button', {
        type: 'button',
        class: pending.action.danger ? 'dg-danger' : 'dg-on',
        onClick: () => {
          const next = ui.confirm;
          ui.confirm = null;
          ui.popup = null;
          runConfirmed(next.action, next.ids, next.bulk);
        },
      }, 'Confirm')));
  }

  async function trackJob(slot) {
    const current = ui.jobs[slot];
    if (!current) return;
    try {
      const status = await grid.pollJob(current.id, current.token);
      ui.jobs[slot] = { ...current, ...status };
      if (status.status === 'done' || status.status === 'failed') {
        if (status.status === 'failed') grid.state.error = status.message || 'The job failed.';
        else ui.notice = status.message || '';
        if (slot === 'bulk') {
          ui.selected = new Set();
          ui.busy = false;
          await grid.load({ fresh: true });
        }
        if (slot === 'import' && status.status === 'done') await grid.load({ fresh: true });
        if (slot === 'export' && status.status === 'done') {
          const job = ui.jobs.export;
          ui.jobs.export = null;
          try { await downloadExport(job); } catch (err) { toast(err.message, 'err'); }
        }
        render();
        return;
      }
      render();
      setTimeout(() => trackJob(slot), 500);
    } catch (e) {
      if (slot === 'bulk') ui.busy = false;
      grid.state.error = e.message;
      ui.jobs[slot] = { ...current, status: 'failed', message: e.message };
      render();
    }
  }

  async function downloadExport(job) {
    const headers = {
      ...(options.csrf ? { 'X-CSRF-TOKEN': options.csrf } : {}),
      ...(options.headers || {}),
      'X-Datagrid-Token': job.token || '',
    };
    const url = `${options.url.replace(/\/$/, '')}/jobs/${encodeURIComponent(job.id)}/download`;
    const res = await fetch(url, { credentials: options.credentials || 'same-origin', headers });
    if (!res.ok) throw new Error('Download failed');
    const blob = await res.blob();
    const link = document.createElement('a');
    const objectUrl = URL.createObjectURL(blob);
    link.href = objectUrl;
    link.download = 'export.csv';
    link.click();
    URL.revokeObjectURL(objectUrl);
  }

  async function runConfirmed(action, ids, bulk) {
    ui.busy = true;
    ui.notice = '';
    grid.state.error = null;
    render();
    try {
      const result = await grid.runAction(action.key, ids, bulk ? { bulk: true } : {});
      if (result && result.job) {
        ui.jobs[bulk ? 'bulk' : 'row'] = { id: result.job, token: result.token, status: 'queued', done: 0, total: ids.length, label: action.label };
        if (bulk) {
          ui.busy = true;
          render();
          trackJob('bulk');
        } else {
          ui.busy = false;
          render();
        }
        return;
      }
      ui.selected = new Set();
      await grid.load({ fresh: true });
      if (!grid.state.error) ui.notice = result?.message || '';
      ui.busy = false;
      render();
    } catch (e) {
      grid.state.error = e.message;
      ui.busy = false;
      render();
    }
  }

  function invoke(action, ids, bulk) {
    if (!ids.length || ui.busy) return;
    if (action.confirm) {
      ui.confirm = { action, ids, bulk };
      ui.popup = 'confirm';
      ui.menu = null;
      render();
      return;
    }
    runConfirmed(action, ids, bulk);
  }

  async function startExport() {
    if (jobActive('export')) return;
    ui.menu = null;
    ui.notice = '';
    grid.state.error = null;
    try {
      const result = await grid.exportRows({
        search: grid.state.search,
        sort: grid.state.sort,
        columns: grid.visibleColumns().filter((column) => column.exportable !== false).map((column) => column.key),
        filter: buildFilterTree(grid.state.filters),
      });
      ui.jobs.export = { id: result.job, token: result.token, status: 'queued', done: 0, total: 0 };
      render();
      trackJob('export');
    } catch (e) {
      grid.state.error = e.message;
      render();
    }
  }

  function parseCsvLine(line) {
    const out = [];
    let cur = '';
    let quoted = false;
    for (let i = 0; i < line.length; i++) {
      const ch = line[i];
      if (ch === '"') {
        if (quoted && line[i + 1] === '"') { cur += '"'; i++; }
        else quoted = !quoted;
      } else if (ch === ',' && !quoted) {
        out.push(cur.trim());
        cur = '';
      } else cur += ch;
    }
    out.push(cur.trim());
    return out.filter((header) => header !== '');
  }

  function importTargets() {
    const schema = grid.state.schema;
    const cols = (schema?.columns || []).filter((column) => column.importable);
    const pk = schema?.primaryKey;
    if (pk && !cols.some((column) => column.key === pk)) cols.unshift({ key: pk, label: 'Primary key' });
    return cols;
  }

  function openImport() {
    if (jobActive('import')) return;
    ui.popup = 'import';
    ui.menu = null;
    ui.importError = '';
    render();
  }

  function readImportFile(file) {
    ui.importFile = file;
    ui.importName = file.name;
    ui.importError = '';
    const reader = new FileReader();
    reader.onload = () => {
      const text = String(reader.result || '').replace(/^\uFEFF/, '');
      const first = text.split(/\r?\n/).find((line) => line.trim());
      ui.importHeaders = parseCsvLine(first || '');
      ui.importMap = {};
      const used = new Set();
      ui.importHeaders.forEach((header) => {
        const match = importTargets().find((column) => column.key.toLowerCase() === header.toLowerCase() || column.label.toLowerCase() === header.toLowerCase());
        if (match && !used.has(match.key)) {
          ui.importMap[header] = match.key;
          used.add(match.key);
        }
      });
      render();
    };
    reader.readAsText(file.slice(0, 65536));
  }

  async function submitImport() {
    if (!ui.importFile || jobActive('import')) return;
    const pk = grid.state.schema?.primaryKey;
    if (!Object.values(ui.importMap).includes(pk)) {
      ui.importError = `Map a column to the primary key (${pk}).`;
      render();
      return;
    }
    ui.notice = '';
    grid.state.error = null;
    try {
      const result = await grid.importFile(ui.importFile, ui.importMap);
      ui.jobs.import = { id: result.job, token: result.token, status: 'queued', done: 0, total: 0 };
      ui.popup = null;
      render();
      trackJob('import');
    } catch (e) {
      ui.importError = e.message;
      render();
    }
  }

  function importDialog() {
    const targets = importTargets();
    const used = new Set(Object.values(ui.importMap));
    const rows = ui.importHeaders.map((header) => {
      const current = ui.importMap[header] || '';
      return el('div', { class: 'dg-map' },
        el('span', {}, header),
        el('span', { class: 'dg-muted' }, '→'),
        el('select', { onChange: (e) => { ui.importMap[header] = e.target.value; render(); } },
          el('option', { value: '' }, 'Skip'),
          targets.map((column) => el('option', {
            value: column.key,
            selected: column.key === current,
            disabled: used.has(column.key) && column.key !== current,
          }, column.label))));
    });
    return dialog('Import', el('div', {},
      el('p', { class: 'dg-muted' }, 'Upload a CSV, then map its columns to the importable fields.'),
      el('input', { type: 'file', accept: '.csv,text/csv', onChange: (e) => {
        const file = e.target.files && e.target.files[0];
        if (file) readImportFile(file);
      } }),
      ui.importName && el('p', {}, ui.importName),
      rows.length ? el('div', { class: 'dg-map-list' }, rows) : null,
      ui.importError && el('p', { class: 'dg-err' }, ui.importError),
    ), el('div', { class: 'dg-dialog-f' },
      el('button', { type: 'button', onClick: () => closePopup() }, 'Cancel'),
      el('button', { type: 'button', class: 'dg-on', disabled: !ui.importFile || jobActive('import'), onClick: () => submitImport() }, 'Import')));
  }

  function progressButton(label, slot, onClick) {
    const job = ui.jobs[slot];
    const active = jobActive(slot);
    return el('button', { type: 'button', class: 'dg-with-ring', disabled: active, onClick },
      label,
      active && ring(job.done || 0, job.total || 0));
  }

  function toneClass(item) {
    if (!item) return '';
    if (item.color === 'primary') return 'dg-on';
    if (item.color === 'danger' || item.danger) return 'dg-danger';
    return '';
  }

  function actionHref(action, id) {
    if (!action.href) return null;
    return String(action.href).replaceAll('{id}', encodeURIComponent(id ?? ''));
  }

  function labeledControl(tag, props, item) {
    return el(tag, props, item.icon && icon(item.icon), item.label);
  }

  function actionButtons(actions, ids, bulk) {
    return actions.map((action) => {
      const href = !bulk && actionHref(action, ids[0]);
      if (href) {
        return labeledControl('a', { class: `dg-link ${toneClass(action)}`, href }, action);
      }
      if (action.href) return null;
      return labeledControl('button', {
        type: 'button',
        class: toneClass(action),
        disabled: ui.busy,
        onClick: () => invoke(action, ids, bulk),
      }, action);
    });
  }

  function openButtonMenu(button, anchor) {
    if (ui.menu && ui.menu.key === '__button' && ui.menu.buttonKey === button.key) {
      ui.menu = null;
      render();
      return;
    }
    ui.menu = { key: '__button', anchor: `button:${button.key}`, buttonKey: button.key, items: button.items || [], top: 0, left: 0 };
    ui.popup = null;
    render();
  }

  function customTool(button, inMenu) {
    if (button.items) {
      if (inMenu) {
        return el('div', { class: 'dg-sub' },
          el('span', { class: 'dg-menu-label' }, button.label),
          button.items.map((item) => labeledControl('a', { class: `dg-link ${toneClass(item)}`, href: item.href }, item)));
      }
      return labeledControl('button', {
        type: 'button',
        class: toneClass(button),
        'data-dg-anchor': `button:${button.key}`,
        onClick: (e) => { e.stopPropagation(); openButtonMenu(button, e.currentTarget); },
      }, button);
    }
    return labeledControl('a', { class: `dg-link ${toneClass(button)}`, href: button.href }, button);
  }

  function toolDescriptors() {
    const s = grid.state;
    const activeFilters = s.filters.length;
    const canFilter = filterableColumns().length > 0;
    const custom = (s.schema?.buttons || []).map((button, index) => ({
      key: button.key,
      place: index,
      rank: button.color === 'primary' ? 0 : 50,
      node: (inMenu) => customTool(button, inMenu),
    }));
    const builtins = [
      canFilter && {
        key: 'saved',
        place: 100,
        rank: 60,
        node: () => el('button', { type: 'button', class: ui.popup === 'saved' || ui.saved.length ? 'dg-on' : '', onClick: () => openSaved() }, ui.saved.length ? `Saved (${ui.saved.length})` : 'Saved'),
      },
      canFilter && {
        key: 'filter',
        place: 110,
        rank: 10,
        node: () => el('button', { type: 'button', class: activeFilters ? 'dg-on' : '', onClick: () => openFilter() }, activeFilters ? `Filter (${activeFilters})` : 'Filter'),
      },
      {
        key: 'sort',
        place: 120,
        rank: 20,
        node: () => el('button', { type: 'button', class: s.sort.length ? 'dg-on' : '', onClick: () => { ui.popup = 'sort'; ui.menu = null; render(); } }, s.sort.length ? `Sort (${s.sort.length})` : 'Sort'),
      },
      s.schema?.features?.export !== false && { key: 'export', place: 130, rank: 30, node: () => progressButton('Export', 'export', () => startExport()) },
      s.schema?.features?.import !== false && { key: 'import', place: 140, rank: 40, node: () => progressButton('Import', 'import', () => openImport()) },
    ].filter(Boolean);
    return [...custom, ...builtins];
  }

  function partitionTools(tools) {
    const desktop = [...tools].sort((a, b) => a.place - b.place);
    if (!ui.compact) return { visible: desktop, collapsed: [] };
    const ranked = [...tools].sort((a, b) => a.rank - b.rank || a.place - b.place);
    const primaries = ranked.filter((tool) => tool.rank === 0);
    const keep = primaries.length >= 2 ? primaries : ranked.slice(0, 2);
    const keepKeys = new Set(keep.map((tool) => tool.key));
    const collapsed = ranked.filter((tool) => !keepKeys.has(tool.key));
    if (!collapsed.length) return { visible: desktop, collapsed: [] };
    return { visible: keep, collapsed };
  }

  function openToolsMenu(anchor) {
    if (ui.menu && ui.menu.key === '__tools') {
      ui.menu = null;
      render();
      return;
    }
    ui.menu = { key: '__tools', anchor: '__tools', align: 'end', top: 0, left: 0 };
    ui.popup = null;
    render();
  }

  function toolbarButtons(tools) {
    return [
      ...tools.visible.map((tool) => tool.node(false)),
      tools.collapsed.length ? el('button', {
        type: 'button',
        class: ui.menu && ui.menu.key === '__tools' ? 'dg-on' : '',
        'aria-expanded': ui.menu && ui.menu.key === '__tools' ? 'true' : 'false',
        'aria-label': 'More actions',
        'data-dg-anchor': '__tools',
        onClick: (e) => { e.stopPropagation(); openToolsMenu(e.currentTarget); },
      }, 'More') : null,
    ];
  }

  function buttonMenu() {
    if (!ui.menu || ui.menu.key !== '__button') return null;
    return el('div', { class: 'dg-menu', style: { top: `${ui.menu.top}px`, left: `${ui.menu.left}px` }, onClick: (e) => e.stopPropagation() },
      (ui.menu.items || []).map((item) => labeledControl('a', { class: `dg-link ${toneClass(item)}`, href: item.href }, item)));
  }

  function toolsMenu(collapsed) {
    if (!ui.menu || ui.menu.key !== '__tools' || !collapsed.length) return null;
    return el('div', { class: 'dg-menu', style: { top: `${ui.menu.top}px`, left: `${ui.menu.left}px` }, onClick: (e) => e.stopPropagation() },
      collapsed.map((tool) => tool.node(true)));
  }

  function openRowMenu(row) {
    if (ui.menu && ui.menu.rowId === row.id) { ui.menu = null; render(); return; }
    ui.menu = { key: '__row', anchor: `row:${row.id}`, align: 'end', rowId: row.id, top: 0, left: 0 };
    ui.popup = null;
    render();
  }

  function rowButton(row) {
    return el('button', {
      type: 'button',
      class: 'dg-icon',
      'aria-label': 'Row actions',
      'data-dg-anchor': `row:${row.id}`,
      onClick: (e) => { e.stopPropagation(); openRowMenu(row); },
    }, icon('dots'));
  }

  function rowActionsMenu(actions) {
    if (!ui.menu || ui.menu.key !== '__row') return null;
    const row = grid.state.rows.find((item) => item.id === ui.menu.rowId);
    if (!row || !actions.length) return null;
    return el('div', { class: 'dg-menu', style: { top: `${ui.menu.top}px`, left: `${ui.menu.left}px` }, onClick: (e) => e.stopPropagation() },
      actionButtons(actions, [row.id], false));
  }

  function dismissToast(item) {
    clearTimeout(dismissTimers[item.id]);
    if (item.id === 'toast') ui.toast = null;
    if (item.id === 'notice') ui.notice = '';
    if (item.id === 'error') ui.errorDismissed = item.text;
    render();
  }

  function toastCard(item) {
    return el('div', { class: `dg-toast dg-toast-${item.tone}` },
      el('span', { class: 'dg-toast-mark', 'aria-hidden': 'true' }, icon(item.tone === 'err' ? 'alert' : 'check')),
      el('p', { class: 'dg-toast-text' }, item.text),
      el('button', { type: 'button', class: 'dg-toast-x', 'aria-label': 'Dismiss notification', onClick: () => dismissToast(item) }, icon('close')));
  }

  function paintToasts() {
    const s = grid.state;
    if (!s.error) ui.errorDismissed = '';
    const items = [
      ui.toast && { id: 'toast', text: ui.toast.text, tone: ui.toast.tone === 'err' ? 'err' : 'ok' },
      ui.notice && { id: 'notice', text: ui.notice, tone: 'ok' },
      s.error && s.error !== ui.errorDismissed && { id: 'error', text: s.error, tone: 'err' },
    ].filter(Boolean);
    const signature = items.map((item) => `${item.id}:${item.text}`).join('|');
    if (signature === ui.toastSig) return;
    ui.toastSig = signature;
    toastHost.replaceChildren(...items.map(toastCard));
    items.forEach((item) => {
      clearTimeout(dismissTimers[item.id]);
      dismissTimers[item.id] = setTimeout(() => dismissToast(item), 4200);
    });
  }

  function toast(text, tone) {
    ui.toast = { text, tone: tone || 'ok' };
    ui.toastSig = '';
    render();
  }

  function showTip(anchor, text) {
    document.querySelectorAll('.dg-tip').forEach((node) => node.remove());
    const tip = document.createElement('div');
    tip.className = 'dg-tip';
    tip.textContent = text;
    document.body.append(tip);
    const rect = anchor.getBoundingClientRect();
    const below = rect.top < 72;
    tip.style.left = `${rect.left + rect.width / 2}px`;
    tip.style.top = `${below ? rect.bottom + 8 : rect.top - 8}px`;
    tip.style.transform = below ? 'translate(-50%, 0)' : 'translate(-50%, -100%)';
    setTimeout(() => tip.remove(), 900);
  }

  async function copyValue(text, button) {
    try {
      await navigator.clipboard.writeText(text);
      button.classList.remove('dg-copied');
      void button.offsetWidth;
      button.classList.add('dg-copied');
      button.replaceChildren(icon('check'));
      toast('Copied', 'ok');
      setTimeout(() => {
        button.classList.remove('dg-copied');
        button.replaceChildren(icon('copy'));
      }, 900);
    } catch (e) {
      toast(e.message, 'err');
    }
  }

  let committing = false;
  async function commitEdit() {
    if (!ui.editing || committing) return;
    committing = true;
    const edit = ui.editing;
    try {
      const result = await grid.patchCell(edit.id, edit.field, edit.value);
      const row = grid.state.rows.find((item) => item.id === edit.id);
      if (row) row.values[edit.field] = result.value;
      if (ui.editing === edit) ui.editing = null;
      toast('Saved.', 'ok');
    } catch (e) {
      if (ui.editing === edit) ui.editing.error = e.message;
      toast(e.message, 'err');
    } finally {
      render();
      committing = false;
    }
  }

  function cellContent(column, row) {
    const editing = ui.editing && ui.editing.id === row.id && ui.editing.field === column.key;
    if (editing) {
      return el('span', { class: 'dg-edit' },
        el('input', {
          class: ui.editing.error ? 'dg-invalid' : '',
          'aria-invalid': !!ui.editing.error,
          value: ui.editing.value ?? '',
          onInput: (e) => {
            ui.editing.value = e.target.value;
            if (ui.editing.error) {
              ui.editing.error = '';
              e.target.classList.remove('dg-invalid');
              e.target.removeAttribute('aria-invalid');
            }
          },
          onKeydown: (e) => {
            if (e.key === 'Escape') {
              ui.editing = null;
              e.target.blur();
              render();
            } else if (e.key === 'Enter') {
              e.preventDefault();
              commitEdit();
            }
          },
          onBlur: () => { if (!committing && ui.editing) commitEdit(); },
        }));
    }
    if (column.options?.length && column.editable) {
      return el('span', { class: 'dg-cell' },
        el('button', {
          type: 'button',
          class: 'dg-value',
          'aria-haspopup': 'listbox',
          'data-dg-anchor': `__values:${row.id}:${column.key}`,
          onClick: (e) => { e.stopPropagation(); openValueMenu(row, column); },
        }, el('span', {}, optionSummary(column, row.values[column.key]) || 'Choose'), icon('chevron')),
        column.copy && el('button', {
          type: 'button',
          class: 'dg-icon dg-copy',
          'aria-label': `Copy ${column.label}`,
          onClick: (e) => { e.stopPropagation(); copyValue(optionSummary(column, row.values[column.key]), e.currentTarget); },
        }, icon('copy')));
    }
    const shown = column.options?.length ? optionSummary(column, row.values[column.key]) : renderValue(row, column);
    return el('span', {
      class: 'dg-cell',
      onDblClick: column.editable && !column.options?.length ? (e) => {
        e.stopPropagation();
        ui.editing = { id: row.id, field: column.key, value: row.values[column.key] ?? '', error: '' };
        render();
        body.querySelector('.dg-edit input')?.focus();
      } : undefined,
    },
    shown,
    column.copy && el('button', {
      type: 'button',
      class: 'dg-icon dg-copy',
      'aria-label': `Copy ${column.label}`,
      onClick: (e) => { e.stopPropagation(); copyValue(textOf(shown), e.currentTarget); },
    }, icon('copy')));
  }

  function columnsMenu() {
    if (!ui.menu || ui.menu.key !== '__columns') return null;
    return el('div', { class: 'dg-menu dg-col-menu', style: { top: `${ui.menu.top}px`, left: `${ui.menu.left}px` }, onClick: (e) => e.stopPropagation() },
      columnOrderRows());
  }

  function cellBox(column, child, tag, extra = {}) {
    const pin = ui.pins[column.key];
    const width = widthOf(column);
    const style = { minWidth: `${width}px` };
    if (pin || ui.widths[column.key] || column.width) style.width = `${width}px`;
    if (pin === 'left') style.left = `${extra.left}px`;
    if (pin === 'right') style.right = `${extra.right}px`;
    const classes = [pin === 'left' ? 'dg-pin-left' : '', pin === 'right' ? 'dg-pin-right' : '', extra.edge || ''].filter(Boolean).join(' ');
    return el(tag, { class: classes, style }, child);
  }

  function render() {
    ensurePrefs();
    const s = grid.state;
    if (s.schema && !ui.savedReady && !ui.savedLoading) {
      ui.savedLoading = true;
      refreshSaved();
    }

    if (!s.schema) {
      body.replaceChildren(el('div', { class: 'dg-boot' },
        el('div', { class: 'dg-bar' }, el('span', { class: 'dg-skel', style: { maxWidth: 'none', height: '40px', width: '100%' } })),
        el('div', { class: 'dg-wrap' }, el('table', {}, el('tbody', {}, Array.from({ length: 8 }, () => el('tr', {},
          Array.from({ length: 5 }, () => el('td', {}, el('span', { class: 'dg-skel' }))))))))));
      paintToasts();
      return;
    }

    const cols = orderedColumns();
    const actions = s.schema.actions || [];
    const rowActions = actions.filter((action) => action.row);
    const bulkActions = actions.filter((action) => action.bulk);
    const pageIds = s.rows.map((row) => row.id);
    const allOnPage = pageIds.length > 0 && pageIds.every((id) => ui.selected.has(id));
    const checkW = bulkActions.length ? 48 : 0;
    const actionW = 64;
    let leftCursor = checkW;
    const leftOf = {};
    cols.filter((column) => ui.pins[column.key] === 'left').forEach((column) => {
      leftOf[column.key] = leftCursor;
      leftCursor += widthOf(column);
    });
    let rightCursor = actionW;
    const rightOf = {};
    [...cols].reverse().filter((column) => ui.pins[column.key] === 'right').forEach((column) => {
      rightOf[column.key] = rightCursor;
      rightCursor += widthOf(column);
    });
    const lastLeft = [...cols].reverse().find((column) => ui.pins[column.key] === 'left');
    const firstRight = cols.find((column) => ui.pins[column.key] === 'right');

    const tools = partitionTools(toolDescriptors());
    const toolbar = el('div', { class: 'dg-bar' },
      el('div', { class: 'dg-bar-top' },
        options.title && el('h2', { class: 'dg-title' }, options.title),
        el('div', { class: ui.compact ? 'dg-tools dg-tools-compact' : 'dg-tools' },
          toolbarButtons(tools))),
      el('div', { class: 'dg-search-wrap' }, searchInput));

    const bulkJob = jobActive('bulk') ? ui.jobs.bulk : null;
    const bulkBar = ((bulkActions.length && ui.selected.size > 0) || bulkJob) && el('div', { class: 'dg-bulk', role: 'toolbar', 'aria-label': 'Bulk actions' },
      el('div', { class: 'dg-bulk-lead' },
        bulkJob && ring(bulkJob.done || 0, bulkJob.total || 0),
        el('strong', { class: 'dg-bulk-count' }, bulkJob ? (bulkJob.message || bulkJob.label || 'Working…') : `${ui.selected.size} selected`)),
      !bulkJob && el('div', { class: 'dg-bulk-actions' }, actionButtons(bulkActions, [...ui.selected], true)),
      el('button', { type: 'button', class: 'dg-bulk-clear', disabled: ui.busy, onClick: () => { ui.selected = new Set(); render(); } }, 'Clear'));

    const head = el('tr', {},
      bulkActions.length && el('th', { class: `dg-check dg-pin-left${lastLeft ? '' : ' dg-edge-left'}`, style: { left: '0px', width: '48px', minWidth: '48px' } },
        el('input', { type: 'checkbox', checked: allOnPage, disabled: !pageIds.length || ui.busy, 'aria-label': 'Select this page', onChange: (e) => selectPage(e.target.checked) })),
      cols.map((column) => {
        const sort = s.sort.find((item) => item.field === column.key);
        const button = el('button', { type: 'button', class: 'dg-icon', 'aria-label': `${column.label} menu`, 'data-dg-anchor': `column:${column.key}`, onClick: (e) => { e.stopPropagation(); openColumnMenu(column); } }, icon('dots'));
        return cellBox(column, el('div', { class: 'dg-h' },
          el('span', { title: column.description || '', onClick: column.sortable ? (e) => grid.toggleSort(column.key, e.shiftKey) : undefined }, column.label),
          sort && el('span', { class: 'dg-sort' }, sort.dir === 'asc' ? '▲' : '▼'),
          button), 'th', {
          left: leftOf[column.key],
          right: rightOf[column.key],
          edge: column === lastLeft ? 'dg-edge-left' : column === firstRight ? 'dg-edge-right' : '',
        });
      }),
      el('th', { class: 'dg-pin-right dg-act', style: { right: '0px', width: '64px', minWidth: '64px' } },
        el('button', { type: 'button', class: 'dg-icon', 'aria-label': 'Columns', 'data-dg-anchor': '__columns', onClick: (e) => {
          e.stopPropagation();
          if (ui.menu && ui.menu.key === '__columns') { ui.menu = null; render(); return; }
          ui.menu = { key: '__columns', anchor: '__columns', align: 'end', top: 0, left: 0 };
          ui.popup = null;
          render();
        } }, icon('dots'))));

    const skeleton = s.loading && !s.rows.length && Array.from({ length: 8 }, () => el('tr', { class: 'dg-skel-row' },
      bulkActions.length && el('td', {}, el('span', { class: 'dg-skel dg-skel-short' })),
      cols.map(() => el('td', {}, el('span', { class: 'dg-skel' }))),
      el('td', {}, el('span', { class: 'dg-skel dg-skel-short' }))));
    const rows = skeleton || s.rows.map((row) => el('tr', { class: ui.selected.has(row.id) ? 'dg-selected' : '' },
      bulkActions.length && el('td', { class: `dg-check dg-pin-left${lastLeft ? '' : ' dg-edge-left'}`, style: { left: '0px', width: '48px', minWidth: '48px' } },
        el('input', { type: 'checkbox', checked: ui.selected.has(row.id), disabled: ui.busy, 'aria-label': `Select row ${row.id}`, onChange: (e) => selectId(row.id, e.target.checked) })),
      cols.map((column) => cellBox(column, cellContent(column, row), 'td', {
        left: leftOf[column.key],
        right: rightOf[column.key],
        edge: column === lastLeft ? 'dg-edge-left' : column === firstRight ? 'dg-edge-right' : '',
      })),
      el('td', { class: 'dg-pin-right dg-act', style: { right: '0px', width: '64px', minWidth: '64px' } }, rowActions.length ? rowButton(row) : null)));

    const cards = s.rows.map((row) => el('article', { class: 'dg-card' },
      el('div', { class: 'dg-card-top' },
        bulkActions.length && el('label', { class: 'dg-row' },
          el('input', { type: 'checkbox', checked: ui.selected.has(row.id), disabled: ui.busy, 'aria-label': `Select row ${row.id}`, onChange: (e) => selectId(row.id, e.target.checked) }),
          'Select'),
        rowActions.length ? rowButton(row) : null),
      cols.map((column) => el('div', { class: 'dg-field' }, el('span', {}, column.label), cellContent(column, row)))));

    const showEmpty = !s.rows.length && !s.loading;
    const filteredEmpty = Boolean((s.search || '').trim() || s.filters.length);
    const empty = showEmpty && el('div', { class: 'dg-empty' },
      el('span', { class: 'dg-empty-mark', 'aria-hidden': 'true' }, icon(filteredEmpty ? 'search' : 'empty')),
      el('strong', {}, filteredEmpty ? 'No records found' : 'No records yet'),
      el('p', {}, filteredEmpty
        ? 'Nothing matches the current search or filters.'
        : 'There are no records to show in this grid.'),
      filteredEmpty && el('button', { type: 'button', onClick: () => {
        searchInput.value = '';
        ui.draft = [];
        grid.applyQuery({ search: '', filters: [] });
      } }, 'Clear search and filters'));
    const meta = s.meta;
    const perChoices = [25, 50, 100].filter((n) => n <= (s.schema.limits?.maxPerPage || 200));
    if (meta && !perChoices.includes(s.perPage)) perChoices.unshift(s.perPage);
    const from = !meta || meta.total === 0 ? 0 : (meta.page - 1) * s.perPage + (s.rows.length ? 1 : 0);
    const to = meta ? (meta.page - 1) * s.perPage + s.rows.length : 0;
    const foot = meta && el('div', { class: 'dg-foot' },
      el('label', { class: 'dg-row' }, 'Show per page',
        el('select', { onChange: (e) => grid.setPerPage(Number(e.target.value)) }, perChoices.map((n) => el('option', { value: n, selected: n === s.perPage }, String(n))))),
      el('span', {}, meta.total !== null ? `${from.toLocaleString()}–${to.toLocaleString()} of ${meta.total.toLocaleString()}` : `Page ${meta.page}`),
      el('span', { class: 'dg-pages' },
        el('button', { type: 'button', disabled: meta.page <= 1, 'aria-label': 'Previous page', onClick: () => grid.setPage(meta.page - 1) }, '‹'),
        el('button', { type: 'button', disabled: !meta.hasMore, 'aria-label': 'Next page', onClick: () => grid.setPage(meta.page + 1) }, '›')));

    const openMenuColumn = ui.menu && cols.find((column) => column.key === ui.menu.key);
    const openList = body.querySelector('.dg-col-menu, .dg-col-list');
    const keptListScroll = openList ? openList.scrollTop : 0;
    const scroller = body.querySelector('.dg-wrap');
    const keptScroll = scroller ? { left: scroller.scrollLeft, top: scroller.scrollTop } : null;

    body.replaceChildren(...nodes(
      toolbar,
      s.loading && s.rows.length ? el('div', { class: 'dg-shimmer', role: 'progressbar' }) : null,
      bulkBar,
      !showEmpty && el('div', { class: 'dg-table' },
        el('div', { class: 'dg-wrap' }, el('table', {}, el('thead', {}, head), el('tbody', {}, rows)))),
      !showEmpty && el('div', { class: 'dg-cards' }, ...nodes(s.loading && !s.rows.length ? el('div', { class: 'dg-skel', style: { height: '88px', margin: '12px 16px' } }) : null, cards)),
      empty,
      foot,
      openMenuColumn && columnMenu(openMenuColumn),
      ui.menu && ui.menu.key === '__columns' && columnsMenu(),
      rowActionsMenu(rowActions),
      buttonMenu(),
      toolsMenu(tools.collapsed),
      valueMenu(),
      ui.popup === 'saved' && savedDialog(),
      ui.popup === 'filter' && filterDialog(),
      ui.popup === 'columns' && columnDialog(),
      ui.popup === 'sort' && sortDialog(),
      ui.popup === 'confirm' && confirmDialog(),
      ui.popup === 'import' && importDialog()));
    const nextScroller = body.querySelector('.dg-wrap');
    if (nextScroller && keptScroll) {
      nextScroller.scrollLeft = keptScroll.left;
      nextScroller.scrollTop = keptScroll.top;
      nextScroller.addEventListener('scroll', () => placeOpenMenu(), { passive: true });
    }
    placeOpenMenu();
    const nextList = body.querySelector('.dg-col-menu, .dg-col-list');
    if (nextList) nextList.scrollTop = keptListScroll;
    paintToasts();

    if (document.activeElement === document.body && searchInput.value) searchInput.focus();
  }

  window.addEventListener('resize', () => placeOpenMenu());

  document.addEventListener('click', () => {
    if (!ui.menu) return;
    ui.menu = null;
    render();
  });

  grid.subscribe(() => {
    render();
    if (ui.prefsNeedReload) {
      ui.prefsNeedReload = false;
      grid.load();
    }
  });
  render();
  grid.init();
  return grid;
}
