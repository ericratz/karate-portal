// Instructor student profile — React port of instructor/student_profile.php:
// profile card (inline-editable when it's the viewer's own record — same
// #profileEditBtn Edit→Save contract as ProfileCard), attended sessions with
// the instructor-only bulk-uncheck editor, payments, rank history with
// certificate links, belt tests, linked-family tabs, and the notes card
// (admins read, instructors write). Profile-to-profile links keep their
// legacy student_profile.php?id= hrefs (redirect stubs) — specs assert them.

import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { InstructorProfileSaved, InstructorStudent, InstructorStudentProfile } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, fmtDateWeekday, fmtPhone, money, paymentType, personName } from '../../format';

const typeBadges: Record<string, [string, string]> = {
  admin: ['bg-danger', 'Admin'],
  instructor: ['bg-warning text-dark', 'Instructor'],
  student: ['bg-primary', 'Student'],
  parent: ['bg-info text-dark', 'Parent'],
  guest: ['bg-secondary', 'Guest'],
};

const acctTips: Record<string, string> = {
  student: 'Registration fee paid',
  guest: 'Non-paying participant (registration fee not yet paid)',
  parent: 'Family account',
  instructor: 'Teaches or assists with classes',
  admin: 'Full administrative access',
};

/** "2026-07-01" → "Jul 2026" */
function fmtMonthYear(iso: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso.slice(0, 10) + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return iso;
  return `${d.toLocaleString('en-US', { month: 'short' })} ${d.getFullYear()}`;
}

interface ProfileFormState {
  first_name: string;
  last_name: string;
  date_of_birth: string;
  phone: string;
  email: string;
  ec_name: string;
  ec_phone: string;
  street_address: string;
  city_state_zip: string;
  medical_note: string;
}

function formFromStudent(s: InstructorStudent): ProfileFormState {
  return {
    first_name: s.first_name,
    last_name: s.last_name,
    date_of_birth: s.date_of_birth ?? '',
    phone: s.phone ?? '',
    email: s.email ?? '',
    ec_name: s.emergency_contact_name ?? '',
    ec_phone: s.emergency_contact_phone ?? '',
    street_address: s.street_address ?? '',
    city_state_zip: s.city_state_zip ?? '',
    medical_note: s.medical_note ?? '',
  };
}

export default function StudentProfilePage() {
  const { id } = useParams();
  const studentId = Number(id);

  const [data, setData] = useState<InstructorStudentProfile | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    apiGet<InstructorStudentProfile>(`/instructor/student.php?id=${studentId}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the profile.'));
  }, [studentId]);

  useEffect(() => {
    setData(null);
    setError(null);
    load();
  }, [load]);

  if (!data || error) return <PageState error={error} loading />;

  const s = data.student;
  const name = personName(`${s.first_name} ${s.last_name}`);
  const related = data.family_tabs.filter((t) => t.id !== s.id);

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-3">
        <h4 className="mb-0">
          {name}
          {!s.active && <span className="badge bg-secondary ms-2">Inactive</span>}
        </h4>
        {data.is_admin && (
          <div className="ms-auto d-flex gap-2">
            <a
              href={`../admin/member_card.php?student_id=${s.id}`}
              target="_blank"
              rel="noreferrer"
              className="btn btn-sm btn-outline-secondary"
            >
              Member Card
            </a>
            <a href={`../admin/student_edit.php?id=${s.id}&ref=profile`} className="btn btn-sm btn-success">
              Full Edit
            </a>
          </div>
        )}
      </div>

      {data.family_tabs.length > 0 && (
        <ul className="nav nav-tabs mb-4">
          {data.family_tabs.map((tab) => (
            <li className="nav-item" key={tab.id}>
              <Link className={`nav-link ${tab.id === s.id ? 'active' : ''}`} to={`/instructor/student/${tab.id}`}>
                {personName(tab.name)}
                {tab.role === 'parent' && (
                  <span className="badge bg-info text-dark ms-1" style={{ fontSize: '.6rem', verticalAlign: 'middle' }}>
                    Parent
                  </span>
                )}
              </Link>
            </li>
          ))}
        </ul>
      )}

      <div className="row g-4">
        <div className="col-md-6 d-flex flex-column gap-4">
          <ProfileInfoCard data={data} onSaved={(fresh) => setData({ ...data, student: fresh })} />
          <AttendanceCard data={data} onUpdated={load} />
        </div>

        <div className="col-md-6 d-flex flex-column gap-4">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Payment History</div>
            <div className="card-body p-0" style={{ maxHeight: 320, overflowY: 'auto' }}>
              {data.payments.length === 0 ? (
                <p className="p-3 text-muted">No payments on record.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr><th>Date</th><th>Type</th><th>Method</th><th>Amount</th></tr>
                    </thead>
                    <tbody>
                      {data.payments.map((p, i) => (
                        <tr key={i}>
                          <td>{fmtDate(p.payment_date)}</td>
                          <td>{paymentType(p.payment_type)}</td>
                          <td>{p.payment_method.charAt(0).toUpperCase() + p.payment_method.slice(1)}</td>
                          <td>{money(p.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Rank History</div>
            <div className="card-body p-0" style={{ maxHeight: 320, overflowY: 'auto' }}>
              {data.ranks.length === 0 ? (
                <p className="p-3 text-muted">No ranks recorded.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm mb-0">
                    <thead className="table-light">
                      <tr><th>Rank</th><th>Date Achieved</th><th></th></tr>
                    </thead>
                    <tbody>
                      {data.ranks.map((r) => (
                        <tr key={r.rank_id}>
                          <td>{r.kyu_dan}</td>
                          <td>{fmtMonthYear(r.achieved_date)}</td>
                          <td>
                            <a
                              href={`../admin/certificate.php?student_id=${s.id}&rank_id=${r.rank_id}`}
                              target="_blank"
                              rel="noreferrer"
                              className="btn btn-sm btn-outline-secondary py-0"
                            >
                              Certificate
                            </a>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Belt Test History</span>
            </div>
            <div className="card-body p-0" style={{ maxHeight: 320, overflowY: 'auto' }}>
              {data.belt_tests.length === 0 ? (
                <p className="p-3 text-muted">No belt tests on record.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr>
                        <th>Date</th><th>Testing For</th><th>Score</th>
                        <th className="text-center">Fee</th><th className="text-center">Awarded</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.belt_tests.map((bt) => (
                        <tr key={bt.id}>
                          <td className="text-nowrap">{fmtDate(bt.test_date)}</td>
                          <td>
                            {data.is_admin ? (
                              <Link
                                to={`/instructor/belt-test-edit?id=${bt.id}&ref_pid=${s.id}`}
                                className="text-primary text-decoration-none"
                              >
                                {bt.kyu_dan}
                              </Link>
                            ) : (
                              bt.kyu_dan
                            )}
                          </td>
                          <td>
                            {bt.score !== null ? (
                              <span className={`badge ${bt.result === 'pass' ? 'bg-success' : 'bg-danger'}`}>
                                {bt.score}%
                              </span>
                            ) : (
                              <span className="badge bg-secondary">Pending</span>
                            )}
                          </td>
                          <td className="text-center">
                            {bt.fee_paid ? <span className="text-success">✓</span> : <span className="text-muted">—</span>}
                          </td>
                          <td className="text-center">
                            {bt.result === 'pass' ? (
                              <span className="badge bg-success">Passed</span>
                            ) : bt.result === 'fail' ? (
                              <span className="text-danger">✗</span>
                            ) : (
                              <span className="text-muted">—</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          {related.length > 0 && (
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold">Linked Family</div>
              <div className="card-body p-0">
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <tbody>
                      {related.map((rel) => (
                        <tr key={rel.id}>
                          <td>
                            <Link to={`/instructor/student/${rel.id}`}>{personName(rel.name)}</Link>
                            {rel.role === 'parent' && (
                              <span className="badge bg-info text-dark ms-2" style={{ fontSize: '.7rem' }}>Parent</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      <NotesCard data={data} onAdded={load} />
    </>
  );
}

// ── Profile Info card (inline edit for the viewer's own record) ─────────────

function ProfileInfoCard({
  data,
  onSaved,
}: {
  data: InstructorStudentProfile;
  onSaved: (fresh: InstructorStudent) => void;
}) {
  const s = data.student;
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<ProfileFormState>(() => formFromStudent(s));
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const [badgeCls, badgeLabel] = typeBadges[s.student_type] ?? ['bg-secondary', 'Guest'];
  const tip = acctTips[s.student_type] ?? '';

  const set = (key: keyof ProfileFormState) => (value: string) =>
    setForm((f) => ({ ...f, [key]: value }));

  async function save() {
    setSaving(true);
    setSaveError(null);
    try {
      const result = await apiPost<InstructorProfileSaved>('/instructor/student.php', {
        action: 'update_profile',
        id: s.id,
        ...form,
      });
      onSaved(result.student);
      setEditing(false);
      setSaved(true);
    } catch (e: unknown) {
      setSaveError(e instanceof ApiError ? e.message : 'Could not save the profile.');
    } finally {
      setSaving(false);
    }
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    void save();
  }

  const rows: [string, React.ReactNode][] = [
    ['Date of Birth', s.date_of_birth ? fmtDate(s.date_of_birth) : '—'],
    ['Phone', s.phone ? fmtPhone(s.phone) : '—'],
    ['Email', s.email || '—'],
    ['Emergency Contact', s.emergency_contact_name || '—'],
    ['Emergency Phone', s.emergency_contact_phone ? fmtPhone(s.emergency_contact_phone) : '—'],
    [
      'Address',
      s.street_address || s.city_state_zip ? (
        <>
          {s.street_address}
          {s.street_address && s.city_state_zip && <br />}
          {s.city_state_zip}
        </>
      ) : (
        '—'
      ),
    ],
    ['Member Since', s.registration_date ? fmtDate(s.registration_date) : '—'],
  ];

  return (
    <div id="profile-card" className="card border-0 shadow-sm">
      <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Profile Info</span>
        {data.can_edit_profile && (
          <div className="d-flex gap-2">
            <button
              type="button"
              id="profileCancelBtn"
              className="btn btn-sm btn-secondary"
              style={{ display: editing ? '' : 'none' }}
              onClick={() => {
                setForm(formFromStudent(s));
                setEditing(false);
                setSaveError(null);
              }}
            >
              Cancel
            </button>
            <button
              type="button"
              id="profileEditBtn"
              className={`btn btn-sm ${editing ? 'btn-warning' : 'btn-success'}`}
              disabled={saving}
              onClick={() => {
                if (editing) void save();
                else {
                  setForm(formFromStudent(s));
                  setSaved(false);
                  setEditing(true);
                }
              }}
            >
              {editing ? 'Save' : 'Edit'}
            </button>
          </div>
        )}
      </div>
      <div className="card-body py-2 px-3">
        {saveError && <div className="alert alert-danger py-2 mb-3">{saveError}</div>}
        {saved && !editing && <div className="alert alert-success py-2 mb-3">Profile saved.</div>}

        <div id="profile-view" style={{ display: editing ? 'none' : '' }}>
          {rows.map(([label, value]) => (
            <div className="d-flex py-1 border-bottom" key={label}>
              <div className="text-muted small" style={{ minWidth: 160 }}>{label}</div>
              <div>{value}</div>
            </div>
          ))}
          <div className="d-flex py-1 border-bottom">
            <div className="text-muted small" style={{ minWidth: 160 }}>Account Type</div>
            <div>
              <span className={`badge ${badgeCls}`}>{badgeLabel}</span>
              {tip && <span className="text-muted ms-1" title={tip}>ⓘ</span>}
            </div>
          </div>
          <div className="d-flex py-1 border-bottom">
            <div className="text-muted small" style={{ minWidth: 160 }}>Waiver</div>
            <div>
              {s.injury_waiver ? (
                <>
                  <span className="text-success">✓</span>{' '}
                  {s.injury_waiver_date ? fmtDate(s.injury_waiver_date) : ''}
                  <a href={`../admin/waiver_view.php?student_id=${s.id}`} className="btn btn-sm btn-outline-secondary ms-2">
                    View
                  </a>
                </>
              ) : (
                '—'
              )}
            </div>
          </div>
          <div className="d-flex py-1 border-bottom">
            <div className="text-muted small" style={{ minWidth: 160 }}>Account</div>
            <div>{s.username ?? <span className="text-muted">No login</span>}</div>
          </div>
          <div className="d-flex py-1 border-bottom">
            <div className="text-muted small" style={{ minWidth: 160 }}>Last Login</div>
            <div>{s.last_login ? fmtDate(s.last_login) : '—'}</div>
          </div>
          <div className="d-flex py-1 border-bottom">
            <div className="text-muted small" style={{ minWidth: 160 }}>Uniform Size</div>
            <div>{s.uniform_size || '—'}</div>
          </div>
          <div className="d-flex py-1 border-bottom">
            <div className="text-muted small" style={{ minWidth: 160 }}>Belt Size</div>
            <div>{s.belt_size || '—'}</div>
          </div>
          <div className="d-flex py-1">
            <div className="text-muted small" style={{ minWidth: 160 }}>Medical Note</div>
            <div style={{ whiteSpace: 'pre-line' }}>{s.medical_note || '—'}</div>
          </div>
        </div>

        {data.can_edit_profile && (
          <div id="profile-edit" style={{ display: editing ? '' : 'none' }}>
            <form id="profile-form" className="row g-3" onSubmit={submit}>
              <div className="col-6">
                <label className="form-label">First Name *</label>
                <input type="text" name="first_name" className="form-control" required
                       value={form.first_name} onChange={(e) => set('first_name')(e.target.value)} />
              </div>
              <div className="col-6">
                <label className="form-label">Last Name *</label>
                <input type="text" name="last_name" className="form-control" required
                       value={form.last_name} onChange={(e) => set('last_name')(e.target.value)} />
              </div>
              <div className="col-6">
                <label className="form-label">Date of Birth</label>
                <input type="date" name="date_of_birth" className="form-control"
                       value={form.date_of_birth} onChange={(e) => set('date_of_birth')(e.target.value)} />
              </div>
              <div className="col-6">
                <label className="form-label">Phone</label>
                <input type="tel" name="phone" className="form-control"
                       value={form.phone} onChange={(e) => set('phone')(e.target.value)} />
              </div>
              <div className="col-12">
                <label className="form-label">Email</label>
                <input type="email" name="email" className="form-control"
                       value={form.email} onChange={(e) => set('email')(e.target.value)} />
              </div>
              <div className="col-6">
                <label className="form-label">Emergency Contact</label>
                <input type="text" name="ec_name" className="form-control"
                       value={form.ec_name} onChange={(e) => set('ec_name')(e.target.value)} />
              </div>
              <div className="col-6">
                <label className="form-label">Emergency Phone</label>
                <input type="tel" name="ec_phone" className="form-control"
                       value={form.ec_phone} onChange={(e) => set('ec_phone')(e.target.value)} />
              </div>
              <div className="col-12">
                <label className="form-label">Street Address</label>
                <input type="text" name="street_address" className="form-control"
                       value={form.street_address} onChange={(e) => set('street_address')(e.target.value)} />
              </div>
              <div className="col-12">
                <label className="form-label">City, State, ZIP</label>
                <input type="text" name="city_state_zip" className="form-control"
                       value={form.city_state_zip} onChange={(e) => set('city_state_zip')(e.target.value)} />
              </div>
              <div className="col-12">
                <label className="form-label">Medical Note</label>
                <textarea name="medical_note" className="form-control" rows={2}
                          value={form.medical_note} onChange={(e) => set('medical_note')(e.target.value)} />
              </div>
            </form>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Sessions Attended card (instructor-only bulk uncheck) ───────────────────

function AttendanceCard({
  data,
  onUpdated,
}: {
  data: InstructorStudentProfile;
  onUpdated: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [checked, setChecked] = useState<Set<number>>(new Set());
  const [saving, setSaving] = useState(false);
  const canEdit = !data.is_admin && data.attended_sessions.length > 0;

  function startEdit() {
    setChecked(new Set(data.attended_sessions.map((a) => a.session_id)));
    setEditing(true);
  }

  async function confirm() {
    setSaving(true);
    try {
      await apiPost('/instructor/student.php', {
        action: 'update_attendance',
        id: data.student.id,
        present_session_ids: [...checked],
      });
      setEditing(false);
      onUpdated();
    } finally {
      setSaving(false);
    }
  }

  return (
    <div id="att-card" className="card border-0 shadow-sm">
      <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Sessions Attended</span>
        <div className="d-flex gap-2">
          {canEdit && !editing && (
            <button type="button" id="attEditBtn" className="btn btn-sm btn-outline-secondary" onClick={startEdit}>
              Edit
            </button>
          )}
          {canEdit && editing && (
            <button type="button" id="attConfirmBtn" className="btn btn-sm btn-success"
                    disabled={saving} onClick={() => void confirm()}>
              Confirm
            </button>
          )}
        </div>
      </div>
      <div className="card-body p-0" style={{ maxHeight: 320, overflowY: 'auto' }}>
        {data.attended_sessions.length === 0 ? (
          <p className="p-3 text-muted">No classes recorded.</p>
        ) : (
          <div className="table-responsive">
            <table className="table table-sm table-hover mb-0">
              <tbody>
                {data.attended_sessions.map((a) => (
                  <tr key={a.session_id}>
                    <td>
                      <Link to={`/instructor/attendance?date=${a.session_date}`} className="text-primary text-decoration-none">
                        {fmtDateWeekday(a.session_date)}
                      </Link>
                    </td>
                    {editing && (
                      <td>
                        <input
                          type="checkbox"
                          className="form-check-input"
                          name="att_present[]"
                          value={a.session_id}
                          checked={checked.has(a.session_id)}
                          onChange={() =>
                            setChecked((c) => {
                              const next = new Set(c);
                              if (next.has(a.session_id)) next.delete(a.session_id);
                              else next.add(a.session_id);
                              return next;
                            })
                          }
                        />
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Notes card: admins read the list, instructors write-only ────────────────

function NotesCard({ data, onAdded }: { data: InstructorStudentProfile; onAdded: () => void }) {
  const [content, setContent] = useState('');
  const [msg, setMsg] = useState<string | null>(null);
  const [noteError, setNoteError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  if (data.is_admin) {
    return (
      <div id="notes-card" className="card border-0 shadow-sm mt-4">
        <div className="card-header bg-white fw-semibold">
          Student Notes
          <span className="text-muted fw-normal small ms-2">({data.notes.length})</span>
        </div>
        <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
          {data.notes.length === 0 ? (
            <p className="p-3 text-muted">No notes yet.</p>
          ) : (
            data.notes.map((n) => (
              <div className="border-bottom p-3" key={n.id}>
                <small className="text-muted d-block mb-1">
                  {new Date(n.created_at.replace(' ', 'T')).toLocaleString('en-US', {
                    day: '2-digit', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit',
                  })}{' '}
                  · <strong>{n.username ?? 'unknown'}</strong>
                </small>
                <p className="mb-0 small" style={{ whiteSpace: 'pre-line' }}>{n.content}</p>
              </div>
            ))
          )}
        </div>
      </div>
    );
  }

  async function saveNote(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setNoteError(null);
    try {
      await apiPost('/instructor/student.php', {
        action: 'add_note',
        id: data.student.id,
        content,
      });
      setContent('');
      setMsg('Note saved.');
      onAdded();
    } catch (err: unknown) {
      setNoteError(err instanceof ApiError ? err.message : 'Could not save the note.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div id="notes-card" className="card border-0 shadow-sm mt-4">
      <div className="card-header bg-white fw-semibold">Add Note</div>
      <div className="card-body">
        {msg && <div className="alert alert-success py-2 mb-3">{msg}</div>}
        {noteError && <div className="alert alert-danger py-2 mb-3">{noteError}</div>}
        <form onSubmit={(e) => void saveNote(e)}>
          <textarea
            name="note_content"
            className="form-control form-control-sm mb-2"
            rows={3}
            placeholder="Add a private note…"
            required
            value={content}
            onChange={(e) => setContent(e.target.value)}
          />
          <button type="submit" className="btn btn-sm btn-primary" disabled={saving}>Save Note</button>
        </form>
      </div>
    </div>
  );
}
