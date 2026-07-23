// Donations — React port of admin/donations.php: collapsible record form with
// the optional donor picker (pick a student to link, or free-type any name),
// method/year filters (server-side WHERE like the old page), results table
// with shown total and the Edit-toggle delete column.

import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { AdminDonationsData } from '../../api/types';
import { PageState } from '../../components/shared';
import StudentPicker from '../../components/StudentPicker';
import { fmtDate, personName } from '../../format';

const METHOD_LABELS: Record<string, string> = {
  cash: 'Cash',
  check: 'Check',
  paypal: 'PayPal',
  mail: 'Mail',
};

export default function Donations() {
  const [searchParams, setSearchParams] = useSearchParams();
  const fMethod = searchParams.get('method') ?? '';
  const fYear = Number(searchParams.get('year') ?? 0);

  const [data, setData] = useState<AdminDonationsData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState('');
  const [formError, setFormError] = useState('');
  const [editing, setEditing] = useState(false);
  const [formOpen, setFormOpen] = useState(false);

  // Record form state
  const [amount, setAmount] = useState('');
  const [method, setMethod] = useState('paypal');
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [donorStudent, setDonorStudent] = useState<{ id: number; label: string } | null>(null);
  const [donorName, setDonorName] = useState('');
  const [notes, setNotes] = useState('');
  const amountRef = useRef<HTMLInputElement>(null);

  const load = useCallback(() => {
    const qs = new URLSearchParams();
    if (fMethod) qs.set('method', fMethod);
    if (fYear) qs.set('year', String(fYear));
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    apiGet<AdminDonationsData>(`/admin/donations.php${suffix}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load donations.'));
  }, [fMethod, fYear]);

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
    const amt = parseFloat(amount);
    if (!(amt > 0)) {
      const el = amountRef.current;
      if (el) {
        el.setCustomValidity('Please enter a donation amount.');
        el.reportValidity();
      }
      return;
    }
    setFormError('');
    try {
      await apiPost('/admin/donations.php', {
        action: 'record',
        amount,
        payment_method: method,
        donor_name: donorStudent ? '' : donorName,
        notes,
        payment_date: date,
        student_id: donorStudent?.id ?? 0,
      });
      setMsg('Donation recorded.');
      setAmount('');
      setDonorStudent(null);
      setDonorName('');
      setNotes('');
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not record the donation.');
    }
  };

  const remove = async (id: number) => {
    if (!window.confirm('Delete this donation record? This cannot be undone.')) return;
    try {
      await apiPost('/admin/donations.php', { action: 'delete', id });
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not delete the donation.');
    }
  };

  if (!data || error) return <PageState error={error} loading />;

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h3 className="mb-0">Donations</h3>
        <button className="btn btn-success btn-sm" onClick={() => setFormOpen((v) => !v)}>
          + Record Donation
        </button>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {formError && <div className="alert alert-danger">{formError}</div>}

      {/* Record form (collapse) */}
      <div className={`collapse mb-4${formOpen ? ' show' : ''}`} id="addDonationForm">
        <div className="card border-0 shadow-sm">
          <div className="card-header bg-white fw-semibold">Record Donation</div>
          <div className="card-body">
            <form id="addDonForm" className="row g-3" onSubmit={record} noValidate>
              <div className="col-md-2">
                <label className="form-label">Amount *</label>
                <div className="input-group">
                  <span className="input-group-text">$</span>
                  <input
                    ref={amountRef}
                    type="number"
                    name="amount"
                    id="donAmountInput"
                    className="form-control"
                    step="0.01"
                    min="0.01"
                    required
                    placeholder="0.00"
                    value={amount}
                    onChange={(e) => {
                      e.target.setCustomValidity('');
                      setAmount(e.target.value);
                    }}
                  />
                </div>
              </div>

              <div className="col-md-3">
                <label className="form-label">Method *</label>
                <select
                  name="payment_method"
                  className="form-select"
                  required
                  value={method}
                  onChange={(e) => setMethod(e.target.value)}
                >
                  <option value="cash">Cash</option>
                  <option value="check">Check</option>
                  <option value="paypal">PayPal</option>
                  <option value="mail">Mail</option>
                </select>
              </div>

              <div className="col-md-3">
                <label className="form-label">Date</label>
                <input
                  type="date"
                  name="payment_date"
                  className="form-control"
                  value={date}
                  onChange={(e) => setDate(e.target.value)}
                />
              </div>

              <div className="col-md-4">
                <label className="form-label">
                  Donor <small className="text-muted">(optional — anonymous if blank; pick a student to link, or type any name)</small>
                </label>
                <StudentPicker
                  students={data.students}
                  idBase="donStudent"
                  btnClass="don-stu-btn"
                  selected={donorStudent}
                  onSelect={(id, label) => setDonorStudent({ id, label })}
                  onClear={() => setDonorStudent(null)}
                  placeholder="Type donor name…"
                  overlay={false}
                  inputName="donor_name"
                  onInputChange={setDonorName}
                />
              </div>

              <div className="col-md-8">
                <label className="form-label">Notes</label>
                <input
                  type="text"
                  name="notes"
                  className="form-control"
                  placeholder="Optional"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                />
              </div>

              <div className="col-12">
                <button type="submit" className="btn btn-success">Save Donation</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div id="donations-page-body">
        {/* Filters */}
        <div className="card border-0 shadow-sm mb-3">
          <div className="card-body py-2">
            <form className="row g-2 align-items-end" onSubmit={(e) => e.preventDefault()}>
              <div className="col-md-2">
                <label className="form-label small mb-1">Method</label>
                <select
                  name="method"
                  className="form-select form-select-sm"
                  value={fMethod}
                  onChange={(e) => setFilter('method', e.target.value)}
                >
                  <option value="">All Methods</option>
                  {Object.entries(METHOD_LABELS).map(([v, l]) => (
                    <option key={v} value={v}>{l}</option>
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
              {(fMethod || fYear !== 0) && (
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

        {/* Results */}
        <div id="donations-results" className="card border-0 shadow-sm">
          <div className="card-header bg-white d-flex justify-content-between align-items-center">
            <span className="fw-semibold">
              {data.donations.length} donation{data.donations.length !== 1 ? 's' : ''}
            </span>
            <div className="d-flex align-items-center gap-3">
              <span className="text-success fw-semibold">Total: ${data.total_shown.toFixed(2)}</span>
              {data.donations.length > 0 && (
                <button
                  id="editToggle"
                  className={editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary'}
                  onClick={() => setEditing((v) => !v)}
                >
                  {editing ? 'Done' : 'Edit'}
                </button>
              )}
            </div>
          </div>
          <div className="card-body p-0">
            {data.donations.length === 0 ? (
              <p className="p-3 text-muted">No donations match the filter.</p>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table id="donationsTable" className={`table table-sm table-hover mb-0${editing ? ' editing' : ''}`}>
                  <thead className="table-light">
                    <tr>
                      <th>Date</th>
                      <th>Donor</th>
                      <th>Method</th>
                      <th>Notes</th>
                      <th>By</th>
                      <th>Amount</th>
                      <th className="delete-col" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.donations.map((d) => (
                      <tr key={d.id}>
                        <td className="text-nowrap">{fmtDate(d.payment_date)}</td>
                        <td>
                          {d.student_id ? (
                            <a href={`#/admin/student-edit?id=${d.student_id}`} className="text-decoration-none">
                              {personName(d.donor_name || d.student_name || '')}
                            </a>
                          ) : (
                            d.donor_name ?? '—'
                          )}
                        </td>
                        <td>{METHOD_LABELS[d.payment_method] ?? d.payment_method}</td>
                        <td className="text-muted small">{d.notes ?? ''}</td>
                        <td className="text-muted small">{d.recorded_by_name ?? '—'}</td>
                        <td className="fw-semibold">${d.amount.toFixed(2)}</td>
                        <td className="delete-col">
                          <button
                            type="button"
                            className="btn btn-sm btn-outline-danger py-0"
                            onClick={() => void remove(d.id)}
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
