// Payments — React port of admin/payments.php: collapsible record form with
// the required student picker (?action=add opens it, &student_id=/&type=
// prefill it — the dashboard's Record Payment links rely on that), per-type
// auto-amount, duplicate-tuition warning, student/type/method/year filters
// (server-side WHERE like the old page), the "+" per-row prefill button, and
// the Edit-toggle inline edit rows + delete column.

import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { AdminPayment, AdminPaymentsData, PaymentRecorded } from '../../api/types';
import { PageState } from '../../components/shared';
import StudentPicker from '../../components/StudentPicker';
import type { StudentPickerHandle } from '../../components/StudentPicker';
import { fmtDate, paymentType, personName } from '../../format';

const TYPE_OPTIONS: [string, string][] = [
  ['monthly_tuition', 'Monthly Tuition'],
  ['registration', 'Registration Fee'],
  ['belt_test', 'Belt Test Fee'],
  ['slc_training', 'SLC Training'],
  ['seminar', 'Seminar'],
  ['other', 'Other'],
];

const METHOD_LABELS: Record<string, string> = {
  paypal: 'PayPal',
  cash: 'Cash',
  check: 'Check',
  mail: 'Mail',
};

interface EditForm {
  payment_date: string;
  payment_type: string;
  payment_method: string;
  amount: string;
  transaction_id: string;
  notes: string;
  month_covered: string;
}

/** Inline edit row (PHP's #edit-row-N) — always in the DOM, shown on toggle. */
function EditRow({
  p,
  open,
  onCancel,
  onSave,
}: {
  p: AdminPayment;
  open: boolean;
  onCancel: () => void;
  onSave: (form: EditForm) => Promise<void>;
}) {
  const [form, setForm] = useState<EditForm>(() => ({
    payment_date: p.payment_date.slice(0, 10),
    payment_type: p.payment_type,
    payment_method: p.payment_method,
    amount: String(p.amount),
    transaction_id: p.transaction_id ?? '',
    notes: p.notes ?? '',
    month_covered: p.month_covered ? p.month_covered.slice(0, 7) : '',
  }));

  return (
    <tr id={`edit-row-${p.id}`} style={{ display: open ? '' : 'none' }}>
      <td colSpan={11}>
        <form
          className="row g-2 align-items-end py-1"
          onSubmit={(e) => {
            e.preventDefault();
            void onSave(form);
          }}
        >
          <div className="col-auto">
            <label className="form-label small mb-1">Date</label>
            <input
              type="date"
              name="payment_date"
              className="form-control form-control-sm"
              required
              value={form.payment_date}
              onChange={(e) => setForm({ ...form, payment_date: e.target.value })}
            />
          </div>
          <div className="col-auto">
            <label className="form-label small mb-1">Type</label>
            <select
              name="payment_type"
              className="form-select form-select-sm"
              value={form.payment_type}
              onChange={(e) => setForm({ ...form, payment_type: e.target.value })}
            >
              {TYPE_OPTIONS.map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          <div className="col-auto">
            <label className="form-label small mb-1">Method</label>
            <select
              name="payment_method"
              className="form-select form-select-sm"
              value={form.payment_method}
              onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
            >
              {Object.entries(METHOD_LABELS).map(([v, l]) => (
                <option key={v} value={v}>{l}</option>
              ))}
            </select>
          </div>
          <div className="col-auto" style={{ width: 110 }}>
            <label className="form-label small mb-1">Amount</label>
            <div className="input-group input-group-sm">
              <span className="input-group-text">$</span>
              <input
                type="number"
                name="amount"
                className="form-control"
                step="0.01"
                min="0.01"
                required
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })}
              />
            </div>
          </div>
          <div className="col-auto" style={{ width: 160 }}>
            <label className="form-label small mb-1">Transaction ID</label>
            <input
              type="text"
              name="transaction_id"
              className="form-control form-control-sm"
              value={form.transaction_id}
              onChange={(e) => setForm({ ...form, transaction_id: e.target.value })}
            />
          </div>
          <div className="col-auto" style={{ width: 160 }}>
            <label className="form-label small mb-1">Notes</label>
            <input
              type="text"
              name="notes"
              className="form-control form-control-sm"
              value={form.notes}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
            />
          </div>
          <div className="col-auto">
            <button type="submit" className="btn btn-sm btn-success">Save</button>
            <button type="button" className="btn btn-sm btn-secondary toggle-edit-row-btn" onClick={onCancel}>
              {' '}Cancel
            </button>
          </div>
        </form>
      </td>
    </tr>
  );
}

export default function Payments() {
  const [searchParams, setSearchParams] = useSearchParams();
  const fStudent = Number(searchParams.get('student_id') ?? 0);
  const fType = searchParams.get('type') ?? '';
  const fMethod = searchParams.get('method') ?? '';
  const fYear = Number(searchParams.get('year') ?? 0);

  const [data, setData] = useState<AdminPaymentsData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState('');
  const [dupWarning, setDupWarning] = useState('');
  const [formError, setFormError] = useState('');
  const [editing, setEditing] = useState(false);
  const [openEditRows, setOpenEditRows] = useState<Set<number>>(new Set());

  // Record form — ?action=add opens it; &student_id/&type prefill it
  const [formOpen, setFormOpen] = useState(() => searchParams.get('action') === 'add');
  const [payStudent, setPayStudent] = useState<{ id: number; label: string } | null>(null);
  const [amount, setAmount] = useState('');
  const [type, setType] = useState(() => {
    const t = searchParams.get('type') ?? '';
    return TYPE_OPTIONS.some(([v]) => v === t) ? t : 'monthly_tuition';
  });
  const [method, setMethod] = useState('paypal');
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [txn, setTxn] = useState('');
  const [notes, setNotes] = useState('');
  const [payerName, setPayerName] = useState('');
  const [payerNote, setPayerNote] = useState('');
  const payPicker = useRef<StudentPickerHandle>(null);
  const formCard = useRef<HTMLDivElement>(null);
  // Tracks the last auto-filled amount so a hand-edited value is never clobbered
  const lastAutoValue = useRef('');

  const load = useCallback(() => {
    const qs = new URLSearchParams();
    if (fStudent) qs.set('student_id', String(fStudent));
    if (fType) qs.set('type', fType);
    if (fMethod) qs.set('method', fMethod);
    if (fYear) qs.set('year', String(fYear));
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    apiGet<AdminPaymentsData>(`/admin/payments.php${suffix}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load payments.'));
  }, [fStudent, fType, fMethod, fYear]);

  useEffect(load, [load]);

  // ?action=add&student_id=N — preselect the student once the list arrives
  useEffect(() => {
    if (!data || searchParams.get('action') !== 'add') return;
    const pre = Number(searchParams.get('student_id') ?? 0);
    if (pre && !payStudent) {
      const s = data.students.find((st) => st.id === pre);
      if (s) setPayStudent({ id: s.id, label: `${s.first_name} ${s.last_name}` });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data]);

  // Per-type auto-amount — same "only overwrite untouched values" rule as the
  // old TYPE_FEES script
  useEffect(() => {
    if (!data) return;
    const fee = data.fees[type];
    const current = parseFloat(amount);
    const isBlankOrZero = !amount || Number.isNaN(current) || current === 0;
    const isUntouched = amount === lastAutoValue.current;
    if ((isBlankOrZero || isUntouched) && fee !== undefined) {
      setAmount(String(fee));
      lastAutoValue.current = String(fee);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type, data]);

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
    if (!payStudent) {
      payPicker.current?.reportMissing('Please select a student.');
      return;
    }
    setFormError('');
    try {
      const res = await apiPost<PaymentRecorded>('/admin/payments.php', {
        action: 'record',
        student_id: payStudent.id,
        amount,
        payment_type: type,
        payment_method: method,
        payment_date: date,
        month_covered: type === 'monthly_tuition' ? date.slice(0, 7) : '',
        transaction_id: txn,
        notes,
        payer_name: payerName,
        payer_note: payerNote,
      });
      setMsg('Payment recorded.');
      setDupWarning(
        res.dup_count > 0
          ? `Heads up: this student now has ${res.dup_count} tuition payments recorded for that month. If this was accidental, delete the extra one below.`
          : '',
      );
      setPayStudent(null);
      setTxn('');
      setNotes('');
      setPayerName('');
      setPayerNote('');
      load();
    } catch (err: unknown) {
      setMsg('');
      setFormError(err instanceof ApiError ? err.message : 'Could not record the payment.');
    }
  };

  const remove = async (id: number) => {
    if (!window.confirm('Delete this payment? This cannot be undone.')) return;
    try {
      await apiPost('/admin/payments.php', { action: 'delete', id });
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not delete the payment.');
    }
  };

  const saveEdit = async (id: number, form: EditForm) => {
    setFormError('');
    try {
      await apiPost('/admin/payments.php', { action: 'edit', id, ...form });
      setOpenEditRows((prev) => {
        const next = new Set(prev);
        next.delete(id);
        return next;
      });
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not save the payment.');
    }
  };

  const toggleEditRow = (id: number) => {
    setOpenEditRows((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const prefillPayment = (studentId: number, studentName: string) => {
    setFormOpen(true);
    setPayStudent({ id: studentId, label: studentName });
    formCard.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  if (!data || error) return <PageState error={error} loading />;

  const filtering = fStudent !== 0 || fType !== '' || fMethod !== '' || fYear !== 0;
  const filterStudent = fStudent ? data.students.find((s) => s.id === fStudent) : null;

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h3 className="mb-0">Payments</h3>
        <div className="d-flex gap-2">
          <a href="#/admin/student-edit" className="btn btn-success btn-sm">+ New Participant</a>
          <button className="btn btn-success btn-sm" onClick={() => setFormOpen((v) => !v)}>
            + Record Payment
          </button>
        </div>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {dupWarning && <div className="alert alert-warning">{dupWarning}</div>}
      {formError && <div className="alert alert-danger">{formError}</div>}

      {/* Record form (collapse) */}
      <div className={`collapse${formOpen ? ' show' : ''} mb-4`} id="addPaymentForm" ref={formCard}>
        <div className="card border-0 shadow-sm">
          <div className="card-header bg-white fw-semibold">Record Manual Payment</div>
          <div className="card-body">
            <form id="addPayForm" className="row g-3" onSubmit={record} noValidate>
              <div className="col-md-4">
                <label className="form-label">Student *</label>
                <StudentPicker
                  ref={payPicker}
                  students={data.students}
                  idBase="payGrantStudent"
                  btnClass="pay-grant-stu-btn"
                  selected={payStudent}
                  onSelect={(id, label) => setPayStudent({ id, label })}
                  onClear={() => setPayStudent(null)}
                  placeholder="Type student name…"
                  overlay={false}
                  required
                />
              </div>

              <div className="col-md-2">
                <label className="form-label">Amount *</label>
                <div className="input-group">
                  <span className="input-group-text">$</span>
                  <input
                    type="number"
                    name="amount"
                    id="paymentAmountInput"
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
                <label className="form-label">Type *</label>
                <select
                  name="payment_type"
                  id="paymentTypeSelect"
                  className="form-select"
                  required
                  value={type}
                  onChange={(e) => setType(e.target.value)}
                >
                  {TYPE_OPTIONS.map(([v]) => (
                    <option key={v} value={v}>{paymentType(v)}</option>
                  ))}
                </select>
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
                <label className="form-label">Transaction ID</label>
                <input
                  type="text"
                  name="transaction_id"
                  className="form-control"
                  placeholder="PayPal transaction ID (optional)"
                  value={txn}
                  onChange={(e) => setTxn(e.target.value)}
                />
              </div>

              <div className="col-md-3">
                <label className="form-label">Notes</label>
                <input
                  type="text"
                  name="notes"
                  className="form-control"
                  value={notes}
                  onChange={(e) => setNotes(e.target.value)}
                />
              </div>

              <div className="col-md-6">
                <label className="form-label">
                  Payer Name <small className="text-muted">(if different from student)</small>
                </label>
                <input
                  type="text"
                  name="payer_name"
                  className="form-control"
                  placeholder="e.g. Parent / guardian name"
                  value={payerName}
                  onChange={(e) => setPayerName(e.target.value)}
                />
              </div>

              <div className="col-md-6">
                <label className="form-label">On-behalf-of Note</label>
                <input
                  type="text"
                  name="payer_note"
                  className="form-control"
                  placeholder="e.g. Paying for John + Jane, covering 2 months"
                  value={payerNote}
                  onChange={(e) => setPayerNote(e.target.value)}
                />
              </div>

              <div className="col-12">
                <button type="submit" className="btn btn-success">Save Payment</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div id="payments-page-body">
        {/* Filters */}
        <div className="card border-0 shadow-sm mb-3">
          <div className="card-body py-2">
            <form className="row g-2 align-items-end" onSubmit={(e) => e.preventDefault()}>
              <div className="col-md-3">
                <label className="form-label small mb-1">Student</label>
                <StudentPicker
                  students={data.students}
                  idBase="payFilterStudent"
                  btnClass="pay-filter-stu-btn"
                  selected={
                    filterStudent
                      ? { id: filterStudent.id, label: `${filterStudent.first_name} ${filterStudent.last_name}` }
                      : null
                  }
                  onSelect={(id) => setFilter('student_id', String(id))}
                  onClear={() => setFilter('student_id', '')}
                  placeholder="Type to filter…"
                  clearLabel="×"
                  small
                  overlay={false}
                />
              </div>
              <div className="col-md-2">
                <label className="form-label small mb-1">Type</label>
                <select
                  name="type"
                  className="form-select form-select-sm"
                  value={fType}
                  onChange={(e) => setFilter('type', e.target.value)}
                >
                  <option value="">All Types</option>
                  {TYPE_OPTIONS.map(([v]) => (
                    <option key={v} value={v}>{paymentType(v)}</option>
                  ))}
                </select>
              </div>
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

        {/* Results */}
        <div id="payments-results" className="card border-0 shadow-sm">
          <div className="card-header bg-white d-flex justify-content-between align-items-center">
            <span className="fw-semibold">
              {data.payments.length} payment{data.payments.length !== 1 ? 's' : ''}
            </span>
            <div className="d-flex align-items-center gap-3">
              <span className="text-success fw-semibold">Total: ${data.total_shown.toFixed(2)}</span>
              {data.payments.length > 0 && (
                <button
                  id="editToggle"
                  className={editing ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-outline-secondary'}
                  onClick={() => {
                    setEditing((v) => {
                      if (v) setOpenEditRows(new Set());
                      return !v;
                    });
                  }}
                >
                  {editing ? 'Done' : 'Edit'}
                </button>
              )}
            </div>
          </div>
          <div className="card-body p-0">
            {data.payments.length === 0 ? (
              <p className="p-3 text-muted">No payments match the filter.</p>
            ) : (
              <div style={{ overflowX: 'auto' }}>
                <table id="paymentsTable" className={`table table-sm table-hover mb-0${editing ? ' editing' : ''}`}>
                  <thead className="table-light">
                    <tr>
                      <th className="col-action" />
                      <th>Date</th>
                      <th>Student</th>
                      <th>Type</th>
                      <th>Method</th>
                      <th>Transaction ID</th>
                      <th>Notes</th>
                      <th>By</th>
                      <th>Amount</th>
                      <th className="edit-col" />
                      <th className="delete-col" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.payments.map((p) => (
                      <PaymentRows
                        key={p.id}
                        p={p}
                        editOpen={openEditRows.has(p.id)}
                        onToggleEdit={() => toggleEditRow(p.id)}
                        onDelete={() => void remove(p.id)}
                        onSave={(form) => saveEdit(p.id, form)}
                        onPrefill={() => prefillPayment(p.student_id, p.student_name)}
                      />
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

function PaymentRows({
  p,
  editOpen,
  onToggleEdit,
  onDelete,
  onSave,
  onPrefill,
}: {
  p: AdminPayment;
  editOpen: boolean;
  onToggleEdit: () => void;
  onDelete: () => void;
  onSave: (form: EditForm) => Promise<void>;
  onPrefill: () => void;
}) {
  return (
    <>
      <tr>
        <td>
          <button
            type="button"
            className="btn btn-sm btn-outline-success py-0 prefill-payment-btn"
            data-student-id={p.student_id}
            data-student-name={p.student_name}
            title="Add payment for this student"
            onClick={onPrefill}
          >
            +
          </button>
        </td>
        <td className="text-nowrap">{fmtDate(p.payment_date)}</td>
        <td>
          <a href={`#/instructor/student/${p.student_id}`} className="text-decoration-none">
            {personName(p.student_name)}
          </a>
          {p.payer_name && <div className="text-muted small">paid by {p.payer_name}</div>}
        </td>
        <td>{paymentType(p.payment_type)}</td>
        <td>{METHOD_LABELS[p.payment_method] ?? p.payment_method}</td>
        <td>{p.transaction_id ?? '—'}</td>
        <td>
          {p.notes ?? ''}
          {p.payer_note && <div className="fst-italic text-muted small">{p.payer_note}</div>}
        </td>
        <td>{p.recorded_by_name ?? '—'}</td>
        <td className="fw-semibold">${p.amount.toFixed(2)}</td>
        <td className="edit-col">
          <button
            type="button"
            className="btn btn-sm btn-outline-primary py-0 toggle-edit-row-btn"
            data-id={p.id}
            onClick={onToggleEdit}
          >
            Edit
          </button>
        </td>
        <td className="delete-col">
          <button type="button" className="btn btn-sm btn-outline-danger py-0" onClick={onDelete}>
            ✕
          </button>
        </td>
      </tr>
      <EditRow p={p} open={editOpen} onCancel={onToggleEdit} onSave={onSave} />
    </>
  );
}
