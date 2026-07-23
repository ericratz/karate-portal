// Email Students — React port of admin/email_students.php: compose card,
// group checkboxes (All / Instructors / Parents / Students / Guests) with
// indeterminate states, the click-anywhere recipient rows, live selected
// count, and the send-confirmation dialog.

import { useEffect, useRef, useState } from 'react';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { EmailRecipient, EmailRecipientsData, EmailSendResult } from '../../api/types';
import { PageState } from '../../components/shared';
import { personName } from '../../format';

const GROUPS: [string, string][] = [
  ['instructors', 'Instructors'],
  ['parents', 'Parents'],
  ['students', 'Students'],
  ['guests', 'Guests'],
];

const TYPE_CLS: Record<string, string> = {
  student: 'bg-primary',
  instructor: 'bg-warning text-dark',
  parent: 'bg-info text-dark',
  guest: 'bg-secondary',
};

const groupOf = (type: string): string =>
  type === 'instructor' || type === 'admin'
    ? 'instructors'
    : type === 'student'
      ? 'students'
      : type === 'parent'
        ? 'parents'
        : 'guests';

/** Checkbox that can render the indeterminate state (a DOM-only property). */
function TriStateCheckbox({
  id,
  className,
  checked,
  indeterminate,
  onChange,
  dataGroup,
}: {
  id: string;
  className: string;
  checked: boolean;
  indeterminate: boolean;
  onChange: () => void;
  dataGroup?: string;
}) {
  const ref = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (ref.current) ref.current.indeterminate = indeterminate;
  }, [indeterminate]);
  return (
    <input
      ref={ref}
      type="checkbox"
      className={className}
      id={id}
      data-group={dataGroup}
      checked={checked}
      onChange={onChange}
    />
  );
}

export default function EmailStudents() {
  const [data, setData] = useState<EmailRecipientsData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState('');
  const [formError, setFormError] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [selected, setSelected] = useState<Set<number>>(new Set());

  useEffect(() => {
    apiGet<EmailRecipientsData>('/admin/email_students.php')
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load recipients.'));
  }, []);

  if (!data || error) return <PageState error={error} loading />;

  const recipients = data.recipients;
  const toggle = (id: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const groupState = (group: string) => {
    const rows = recipients.filter((r) => groupOf(r.student_type) === group);
    const on = rows.filter((r) => selected.has(r.id)).length;
    return {
      checked: rows.length > 0 && on === rows.length,
      indeterminate: on > 0 && on < rows.length,
      rows,
    };
  };

  const toggleGroup = (group: string) => {
    const { checked, rows } = groupState(group);
    setSelected((prev) => {
      const next = new Set(prev);
      rows.forEach((r) => (checked ? next.delete(r.id) : next.add(r.id)));
      return next;
    });
  };

  const allChecked = recipients.length > 0 && selected.size === recipients.length;
  const allIndeterminate = selected.size > 0 && selected.size < recipients.length;
  const toggleAll = () => {
    setSelected(allChecked ? new Set() : new Set(recipients.map((r) => r.id)));
  };

  const send = async (e: React.FormEvent) => {
    e.preventDefault();
    const n = selected.size;
    if (n === 0) {
      window.alert('Please select at least one recipient.');
      return;
    }
    if (!window.confirm(`Send this email to ${n} recipient${n !== 1 ? 's' : ''}?`)) return;
    setFormError('');
    try {
      const res = await apiPost<EmailSendResult>('/admin/email_students.php', {
        subject,
        body,
        send_to: [...selected],
      });
      let flash = `Sent to ${res.sent} recipient${res.sent !== 1 ? 's' : ''}.`;
      if (res.failed) flash += ` ${res.failed} skipped (missing or invalid email).`;
      setMsg(flash);
      setSubject('');
      setBody('');
      setSelected(new Set());
      window.scrollTo({ top: 0 });
    } catch (err: unknown) {
      setMsg('');
      setFormError(err instanceof ApiError ? err.message : 'Sending failed.');
    }
  };

  const RecipientRow = ({ r }: { r: EmailRecipient }) => (
    <tr
      className="recipient-row"
      data-type={r.student_type}
      data-group={groupOf(r.student_type)}
      style={{ cursor: 'pointer' }}
      onClick={(e) => {
        const tag = (e.target as HTMLElement).tagName;
        if (tag === 'INPUT' || tag === 'LABEL') return;
        toggle(r.id);
      }}
    >
      <td>
        <input
          type="checkbox"
          className="form-check-input recipient-chk"
          name="send_to[]"
          value={r.id}
          id={`r${r.id}`}
          checked={selected.has(r.id)}
          onChange={() => toggle(r.id)}
        />
      </td>
      <td className="small">
        <label htmlFor={`r${r.id}`} className="mb-0">
          {personName(r.name)}
        </label>
      </td>
      <td>
        <span className={`badge ${TYPE_CLS[r.student_type] ?? 'bg-secondary'}`}>
          {r.student_type.charAt(0).toUpperCase() + r.student_type.slice(1)}
        </span>
      </td>
      <td className="small">{r.email}</td>
    </tr>
  );

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h3 className="mb-0">Email Students</h3>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {formError && <div className="alert alert-danger">{formError}</div>}

      <form id="emailForm" onSubmit={send}>
        {/* Compose */}
        <div className="card border-0 shadow-sm mb-4">
          <div className="card-header bg-white fw-semibold">Compose</div>
          <div className="card-body">
            <div className="mb-3">
              <label className="form-label">Subject *</label>
              <input
                type="text"
                name="subject"
                className="form-control"
                required
                placeholder="e.g. Class cancelled this Saturday"
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
              />
            </div>

            <div className="mb-3">
              <label className="form-label">Message *</label>
              <textarea
                name="body"
                className="form-control"
                rows={7}
                required
                placeholder={'Your message here…\n\nEach email will be addressed to the recipient by name automatically.'}
                value={body}
                onChange={(e) => setBody(e.target.value)}
              />
            </div>

            <div className="mb-3">
              <div className="d-flex gap-3 flex-wrap align-items-center">
                <div className="form-check">
                  <TriStateCheckbox
                    id="chk_all"
                    className="form-check-input"
                    checked={allChecked}
                    indeterminate={allIndeterminate}
                    onChange={toggleAll}
                  />
                  <label className="form-check-label fw-semibold" htmlFor="chk_all">All</label>
                </div>
                <div className="vr mx-1" />
                {GROUPS.map(([val, label]) => {
                  const st = groupState(val);
                  return (
                    <div className="form-check" key={val}>
                      <TriStateCheckbox
                        id={`chk_${val}`}
                        className="form-check-input group-chk"
                        dataGroup={val}
                        checked={st.checked}
                        indeterminate={st.indeterminate}
                        onChange={() => toggleGroup(val)}
                      />
                      <label className="form-check-label" htmlFor={`chk_${val}`}>{label}</label>
                    </div>
                  );
                })}
                <span className="text-muted small ms-2">— or select individuals in the list below</span>
              </div>
            </div>

            <button type="submit" className="btn btn-primary px-4" id="sendBtn">
              Send Email
            </button>
          </div>
        </div>

        {/* Recipient list */}
        <div className="card border-0 shadow-sm">
          <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
            <span>Recipients</span>
            <span
              id="recipientCount"
              className={`badge${selected.size > 0 ? '' : ' bg-secondary'}`}
              style={selected.size > 0 ? { backgroundColor: '#6f42c1' } : undefined}
            >
              {selected.size} selected
            </span>
          </div>
          <div className="card-body p-0" style={{ maxHeight: 420, overflowY: 'auto' }}>
            <div className="table-responsive">
              <table className="table table-sm table-hover mb-0" id="recipientTable">
                <thead className="table-light">
                  <tr>
                    <th style={{ width: 36 }} className="text-center" aria-label="Selected">✓</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Email</th>
                  </tr>
                </thead>
                <tbody id="recipientBody">
                  {recipients.map((r) => (
                    <RecipientRow key={r.id} r={r} />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </form>
    </>
  );
}
