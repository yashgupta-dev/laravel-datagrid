'use client';

import { useEffect, useMemo, useReducer, useRef, useState } from 'react';
import { createGrid } from '@codecorner/datagrid-core';

/** Subscribe React to the framework-agnostic grid state. */
export function useDatagrid(options) {
  const [, rerender] = useReducer((n) => n + 1, 0);
  const grid = useMemo(() => createGrid(options), [options.url]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const off = grid.subscribe(rerender);
    grid.init();
    return off;
  }, [grid]);

  return grid;
}

/**
 * <DataGrid url="https://api.example.com/datagrid/users" headers={{ Authorization: `Bearer ${token}` }} />
 * Override cells with `cells={{ status: (value, row) => <Badge>{value}</Badge> }}` (keyed by column key or type).
 */
export function DataGrid({ cells = {}, className, ...options }) {
  const grid = useDatagrid(options);
  const { schema, rows, meta, loading, error, sort, hidden } = grid.state;
  const [term, setTerm] = useState('');
  const timer = useRef();

  if (!schema) return <div className={className}>{error ? <p role="alert">{error}</p> : 'Loading…'}</div>;

  const columns = grid.visibleColumns();

  return (
    <div className={className}>
      <input
        type="search"
        value={term}
        placeholder="Search…"
        onChange={(e) => {
          setTerm(e.target.value);
          clearTimeout(timer.current);
          timer.current = setTimeout(() => grid.setSearch(e.target.value), 300);
        }}
      />
      {error && <p role="alert">{error}</p>}
      <div style={{ overflowX: 'auto', opacity: loading ? 0.55 : 1 }}>
        <table>
          <thead>
            <tr>
              {columns.map((c) => {
                const s = sort.find((x) => x.field === c.key);
                return (
                  <th key={c.key} onClick={c.sortable ? (e) => grid.toggleSort(c.key, e.shiftKey) : undefined}
                      aria-sort={s ? (s.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                    {c.label}{s ? (s.dir === 'asc' ? ' ▲' : ' ▼') : ''}
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                {columns.map((c) => {
                  const render = cells[c.key] || cells[c.type];
                  const v = row.values[c.key];
                  return <td key={c.key}>{render ? render(v, row, c) : v === null || v === undefined ? '' : typeof v === 'object' ? JSON.stringify(v) : String(v)}</td>;
                })}
              </tr>
            ))}
          </tbody>
        </table>
        {!rows.length && !loading && <p>No results.</p>}
      </div>
      {meta && (
        <div>
          <button disabled={meta.page <= 1} onClick={() => grid.setPage(meta.page - 1)}>Prev</button>
          {' '}{meta.page}{meta.lastPage ? ` / ${meta.lastPage}` : ''}{' '}
          <button disabled={!meta.hasMore} onClick={() => grid.setPage(meta.page + 1)}>Next</button>
          {meta.total !== null && <span> · {meta.total} results</span>}
        </div>
      )}
    </div>
  );
}
