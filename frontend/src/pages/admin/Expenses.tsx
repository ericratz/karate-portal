// Expenses — React port of admin/expenses.php: collapsible record form,
// type/year filters (server-side WHERE like the old page), and the
// Edit-toggle delete column.

import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { AdminExpensesData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate } from '../../format';

const EXPENSE_TYPES = ['rent', 'equipment', 'utilities', 'supplies', 'other'];

const ucfirst = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

export default function Expenses() {
  const [searchParams, setSearchParams] = useSearchParams();
  const fType = searchParams.get('type') ?? '';
  const fYear = Number(searchParams.get('year') ?? 0);

  const [data, setData] = useState<AdminExpensesData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState('');
  const [formError, setFormError] = useState('');
  const [editing, setEditing] = useState(false);
  const [formOpen, setFormOpen] = useState(false);

  // Record form state
  const [type, setType] = useState('rent');
  const [amount, setAmount] = useState('');
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [description, setDescription] = useState('');

  const load = useCallback(() => {
    const qs = new URLSearchParams();
    if (fType) qs.set('type', fType);
    if (fYear) qs.set('year', String(fYear));
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    apiGet<AdminExpensesData>(`/admin/expenses.php${suffix}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load expenses.'));
  }, [fType, fYear]);

  useEffect(load, [load]);

  const setFilter = (key: string, value: string) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (value) next.set(key, value);
      else next.delete(key);
      return next;
    });
  };

  const record = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError('');
    try {
      await apiPost('/admin/expenses.php', {
        action: 'record',
        expense_type: type,
        amount,
        expense_date: date,
        description,
      });
      setMsg('Expense recorded.');
      setAmount('');
      setDescription('');
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not record the expense.');
    }
  };

  const remove = async (id: number) => {
    if (!window.confirm('Delete this expense? This cannot be undone.')) return;
    try {
      await apiPost('/admin/expenses.php', { action: 'delete', id });
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not delete the expense.');
    }
  };

  if (!data || error) return <PageState error={error} loading />;

  const filtering = fType !== '' || fYear !== 0;

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h3 className="mb-0">Expenses</h3>
        <button className="btn btn-success btn-sm" onClick={() => setFormOpen((v) => !v)}>
          + Record Expense
        </button>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {formError && <div className="alert alert-danger">{formError}</div>}

      {/* Record form (collapse) */}
      <div className={`collapse mb-4${formOpen ? ' show' : ''}`} id="addForm">
        <div className="card border-0 shadow-sm">
          <div className="card-header bg-white fw-semibold">Record Expense</div>
          <div className="card-body">
            <form className="row g-3" onSubmit={record}>
              <div className="col-md-3">
                <label className="form-label">Type *</label>
                <select
                  name="expense_type"
                  className="form-select"
                  required
                  value={type}
                  onChange={(e) => setType(e.target.value)}
                >
                  {EXPENSE_TYPES.map((t) => (
                    <option key={t} value={t}>{ucfirst(t)}</option>
                  ))}
                </select>
              </div>
              <div className="col-md-2">
                <label className="form-label">Amount *</label>
                <div className="input-group">
                  <span className="input-group-text">$</span>
                  <input
                    type="number"
                    name="amount"
                    className="form-control"
                    step="0.01"
                    min="0.01"
                    required
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                  />
                </div>
              </div>
              <div className="col-md-3">
                <label className="form-label">Date *</label>
                <input
                  type="date"
                  name="expense_date"
                  className="form-control"
                  required
                  value={date}
                  onChange={(e) => setDate(e.target.value)}
                />
              </div>
              <div className="col-md-4">
                <label className="form-label">Description</label>
                <input
                  type="text"
                  name="description"
                  className="form-control"
                  placeholder="e.g. Monthly studio rent — July"
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                />
              </div>
              <div className="col-12">
                <button className="btn btn-success">Save Expense</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div id="expenses-page-body">
        {/* Filters */}
        <div className="card border-0 shadow-sm mb-3">
          <div className="card-body py-2">
            <form className="row g-2 align-items-end" onSubmit={(e) => e.preventDefault()}>
              <div className="col-md-2">
                <label className="form-label small mb-1">Type</label>
                <select
                  name="type"
                  className="form-select form-select-sm"
                  value={fType}
                  onChange={(e) => setFilter('type', e.target.value)}
                >
                  <option value="">All Types</option>
                  {EXPENSE_TYPES.map((t) => (
                    <option key={t} value={t}>{ucfirst(t)}</option>
                  ))}
                </select>
              </div>
              <div className="col-md-2">
                <label className="form-label small mb-1">Year</label>
                <select
                  name="year"
                  className="form-select form-select-sm"
                  value={fYear || ''}
                  onChange={(e) => setFilter('year', e.target.value)}
                >
                  <option value="">All Years</option>
                  {data.years.map((y) => (
                    <option key={y} value={y}>{y}</option>
                  ))}
                </select>
              </div>
              {filtering && (
                <div className="col-auto">
                  <button
                    type="button"
                    className="btn btn-filter btn-sm"
                    onClick={() => setSearchParams(new URLSearchParams())}
                  >
                    Clear
                  </button>
                </div>
              )}
            </form>
          </div>
        </div>

        <div className="d-flex align-items-center gap-3 mb-3">
          <span className="ms-auto">
            Total: <strong>${data.total.toFixed(2)}</strong>
          </span>
        </div>

        <div className="card border-0 shadow-sm">
          <div className="card-header bg-white d-flex justify-content-between align-items-center">
            <span className="fw-semibold">
              {data.expenses.length} expense{data.expenses.length !== 1 ? 's' : ''}
            </span>
            {data.expenses.length > 0 && (
              <button
                id="editToggle"
                className={editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary'}
                onClick={() => setEditing((v) => !v)}
              >
                {editing ? 'Done' : 'Edit'}
              </button>
            )}
          </div>
          <div className="card-body p-0">
            {data.expenses.length === 0 ? (
              <p className="p-3 text-muted">No expenses match the filter.</p>
            ) : (
              <div className="table-responsive">
                <table id="expensesTable" className={`table table-hover mb-0${editing ? ' editing' : ''}`}>
                  <thead className="table-light">
                    <tr>
                      <th>Date</th>
                      <th>Type</th>
                      <th>Description</th>
                      <th className="text-end">Amount</th>
                      <th>Recorded By</th>
                      <th className="delete-col" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.expenses.map((e) => (
                      <tr key={e.id}>
                        <td>{fmtDate(e.expense_date)}</td>
                        <td>{ucfirst(e.expense_type)}</td>
                        <td>{e.description ?? '—'}</td>
                        <td className="text-end">${e.amount.toFixed(2)}</td>
                        <td>{e.recorded_by ?? '—'}</td>
                        <td className="delete-col">
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-danger py-0"
                            onClick={() => void remove(e.id)}
                          >
                            ✕
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  );
}
