// Student editor — React port of admin/student_edit.php (the last and biggest
// htmx page): profile view/edit card, attendance checkbox editing, payment /
// rank / belt-test / exemption / guardian cards each with their add-boxes and
// Edit-toggle reveal columns, the notes card, and profile delete. All ids,
// input names, and reveal-class conventions match the old page so the specs
// keep addressing the same DOM. One shared refetch keeps every card in sync
// after any mutation — the job htmx out-of-band swaps used to do.

import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { StudentEditData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, fmtDateLong, fmtDateTime, fmtPhone, paymentType, personName } from '../../format';

const today = () => new Date().toISOString().slice(0, 10);

const WAIVER_TYPE_OPTIONS: [string, string][] = [
  ['monthly_tuition', 'Monthly Tuition'],
  ['registration', 'Registration Fee'],
  ['belt_test', 'Belt Test Fee'],
  ['slc_training', 'SLC Training'],
  ['seminar', 'Seminar'],
  ['all', 'All Fees'],
];

const PAY_TYPE_OPTIONS: [string, string][] = [
  ['monthly_tuition', 'Monthly Tuition'],
  ['registration', 'Registration Fee'],
  ['belt_test', 'Belt Test Fee'],
  ['slc_training', 'SLC Training'],
  ['seminar', 'Seminar'],
  ['other', 'Other'],
  ['donation', 'Donation'],
];

const METHOD_OPTIONS: [string, string][] = [
  ['cash', 'Cash'],
  ['check', 'Check'],
  ['paypal', 'PayPal'],
  ['mail', 'Mail'],
];

const ACCT_TIPS: Record<string, string> = {
  student: 'Paying participant ($30/month tuition)',
  guest: 'Non-paying participant (registration fee not yet paid)',
  parent: 'Family account — one tuition payment covers the whole family',
  instructor: 'Teaches or assists with classes',
  admin: 'Full administrative access',
};

const UNIFORM_SIZES = ['000', '00', '0', '1', '2', '3', '4', '5', '6', '7', '8'];
const BELT_SIZES = ['2', '3', '4', '5', '6', '7', '8'];

interface ProfileForm {
  first_name: string;
  last_name: string;
  date_of_birth: string;
  phone: string;
  email: string;
  ec_name: string;
  ec_phone: string;
  street_address: string;
  city_state_zip: string;
  registration_date: string;
  account_type: string;
  active_override: string;
  uniform_size: string;
  belt_size: string;
  medical_note: string;
}

const emptyProfile: ProfileForm = {
  first_name: '',
  last_name: '',
  date_of_birth: '',
  phone: '',
  email: '',
  ec_name: '',
  ec_phone: '',
  street_address: '',
  city_state_zip: '',
  registration_date: today(),
  account_type: 'guest',
  active_override: 'auto',
  uniform_size: '',
  belt_size: '',
  medical_note: '',
};

/** The profile fields shared by the edit card and the new-student form. */
function ProfileFields({ form, setForm, withStatus }: {
  form: ProfileForm;
  setForm: (f: ProfileForm) => void;
  withStatus: boolean;
}) {
  const f = (key: keyof ProfileForm) => ({
    value: form[key],
    onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) =>
      setForm({ ...form, [key]: e.target.value }),
  });
  return (
    <>
      <div className="col-6">
        <label className="form-label">First Name *</label>
        <input type="text" name="first_name" className="form-control" required {...f('first_name')} />
      </div>
      <div className="col-6">
        <label className="form-label">Last Name *</label>
        <input type="text" name="last_name" className="form-control" required {...f('last_name')} />
      </div>
      <div className="col-6">
        <label className="form-label">Date of Birth</label>
        <input type="date" name="date_of_birth" className="form-control" {...f('date_of_birth')} />
      </div>
      <div className="col-6">
        <label className="form-label">Phone</label>
        <input type="tel" name="phone" className="form-control" {...f('phone')} />
      </div>
      <div className="col-12">
        <label className="form-label">Email</label>
        <input type="email" name="email" className="form-control" {...f('email')} />
      </div>
      <div className="col-6">
        <label className="form-label">Emergency Contact</label>
        <input type="text" name="ec_name" className="form-control" {...f('ec_name')} />
      </div>
      <div className="col-6">
        <label className="form-label">Emergency Phone</label>
        <input type="tel" name="ec_phone" className="form-control" {...f('ec_phone')} />
      </div>
      <div className="col-12">
        <label className="form-label">Street Address</label>
        <input type="text" name="street_address" className="form-control" {...f('street_address')} />
      </div>
      <div className="col-12">
        <label className="form-label">City, State, ZIP</label>
        <input type="text" name="city_state_zip" className="form-control" {...f('city_state_zip')} />
      </div>
      <div className="col-6">
        <label className="form-label">Member Since</label>
        <input type="date" name="registration_date" className="form-control" {...f('registration_date')} />
      </div>
      <div className="col-6">
        <label className="form-label">Account Type</label>
        <select name="account_type" className="form-select" {...f('account_type')}>
          <option value="guest">Guest</option>
          <option value="student">Student</option>
          <option value="parent">Parent</option>
          <option value="instructor">Instructor</option>
        </select>
      </div>
      {withStatus && (
        <div className="col-6">
          <label className="form-label">Active Status</label>
          <select name="active_override" className="form-select" {...f('active_override')}>
            <option value="auto">Auto — inactive after 3 months no attendance</option>
            <option value="1">Set Active</option>
            <option value="0">Set Inactive</option>
          </select>
        </div>
      )}
      <div className="col-12"><hr className="my-1" /></div>
      <div className="col-md-6">
        <label className="form-label">Uniform Size</label>
        <select name="uniform_size" className="form-select" {...f('uniform_size')}>
          <option value="">— not set —</option>
          {UNIFORM_SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>
      <div className="col-md-6">
        <label className="form-label">Belt Size</label>
        <select name="belt_size" className="form-select" {...f('belt_size')}>
          <option value="">— not set —</option>
          {BELT_SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
        </select>
      </div>
      <div className="col-12"><hr className="my-1" /></div>
      <div className="col-12">
        <label className="form-label">Medical Note</label>
        <textarea
          name="medical_note"
          className="form-control"
          rows={2}
          placeholder="Allergies, conditions, medications, etc."
          {...f('medical_note')}
        />
      </div>
    </>
  );
}

export default function StudentEdit() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const id = Number(searchParams.get('id') ?? 0);

  const [data, setData] = useState<StudentEditData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState('');
  const [dupWarning, setDupWarning] = useState('');

  // Card UI state
  const [profileEditing, setProfileEditing] = useState(false);
  const [profileForm, setProfileForm] = useState<ProfileForm>(emptyProfile);
  const [attEditing, setAttEditing] = useState(false);
  const [attPresent, setAttPresent] = useState<Set<number>>(new Set());
  const [payEditing, setPayEditing] = useState(false);
  const [payAddOpen, setPayAddOpen] = useState(false);
  const [payEditRows, setPayEditRows] = useState<Set<number>>(new Set());
  const [rankEditing, setRankEditing] = useState(false);
  const [rankAddOpen, setRankAddOpen] = useState(false);
  const [rankForm, setRankForm] = useState<Record<number, { rank_id: number; achieved_date: string }>>({});
  const [btEditing, setBtEditing] = useState(false);
  const [pwEditing, setPwEditing] = useState(false);
  const [pwAddOpen, setPwAddOpen] = useState(false);
  const [pwEditRows, setPwEditRows] = useState<Set<number>>(new Set());
  const [guardianEditing, setGuardianEditing] = useState(false);
  const [guardianAddOpen, setGuardianAddOpen] = useState(false);
  const [guardianPick, setGuardianPick] = useState('');
  const [notesEditing, setNotesEditing] = useState(false);
  const [noteText, setNoteText] = useState('');
  const [noteEditOpen, setNoteEditOpen] = useState<Set<number>>(new Set());

  const load = useCallback(() => {
    apiGet<StudentEditData>(`/admin/student_edit.php${id ? `?id=${id}` : ''}`)
      .then((d) => {
        setData(d);
        if (d.student) {
          setAttPresent(new Set((d.attendance ?? []).filter((a) => a.present).map((a) => a.session_id)));
          setRankForm(
            Object.fromEntries(
              (d.ranks ?? []).map((r) => [r.sr_id, { rank_id: r.rank_id, achieved_date: r.achieved_date ?? '' }]),
            ),
          );
        }
      })
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the student.'));
  }, [id]);

  useEffect(load, [load]);

  const post = async (payload: Record<string, unknown>): Promise<boolean> => {
    setActionError('');
    try {
      await apiPost('/admin/student_edit.php', { id, ...payload });
      load();
      return true;
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'The action failed.');
      return false;
    }
  };

  if (!data || error) return <PageState error={error} loading />;

  const s = data.student;

  // ── New-student mode ───────────────────────────────────────────
  if (!s) {
    return (
      <NewStudentForm
        onSubmit={async (form) => {
          setActionError('');
          try {
            const res = await apiPost<{ id: number }>('/admin/student_edit.php', {
              action: 'add_student',
              ...form,
            });
            navigate(`/admin/student-edit?id=${res.id}`);
          } catch (e: unknown) {
            setActionError(e instanceof ApiError ? e.message : 'Could not add the student.');
          }
        }}
        actionError={actionError}
      />
    );
  }

  const startProfileEdit = () => {
    setProfileForm({
      first_name: s.first_name,
      last_name: s.last_name,
      date_of_birth: s.date_of_birth ?? '',
      phone: s.phone ?? '',
      email: s.email ?? '',
      ec_name: s.emergency_contact_name ?? '',
      ec_phone: s.emergency_contact_phone ?? '',
      street_address: s.street_address ?? '',
      city_state_zip: s.city_state_zip ?? '',
      registration_date: s.registration_date ?? today(),
      account_type: s.student_type,
      active_override: s.active_override === null ? 'auto' : String(s.active_override),
      uniform_size: s.uniform_size ?? '',
      belt_size: s.belt_size ?? '',
      medical_note: s.medical_note ?? '',
    });
    setProfileEditing(true);
  };

  const confirmProfile = async () => {
    if (await post({ action: 'update_profile', ...profileForm })) setProfileEditing(false);
  };

  const toggleSet = (setter: React.Dispatch<React.SetStateAction<Set<number>>>) => (key: number) =>
    setter((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  const togglePayRow = toggleSet(setPayEditRows);
  const togglePwRow = toggleSet(setPwEditRows);
  const toggleNoteEdit = toggleSet(setNoteEditOpen);

  const deleteProfile = async () => {
    if (
      !window.confirm(
        `Permanently delete ${s.first_name} ${s.last_name}?\n\nThis removes their profile, attendance, payments, and login account. This cannot be undone.`,
      )
    ) {
      return;
    }
    setActionError('');
    try {
      await apiPost('/admin/student_edit.php', { id, action: 'delete_profile' });
      window.location.href = 'students.php';
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'Delete failed.');
    }
  };

  const ovVal = s.active_override === null ? 'auto' : String(s.active_override);
  const viewRows: [string, React.ReactNode][] = [
    ['First Name', personName(s.first_name) || '—'],
    ['Last Name', personName(s.last_name) || '—'],
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
    <>
      <div className="d-flex align-items-center gap-3 mb-4">
        <h4 className="mb-0">Edit: {personName(`${s.first_name} ${s.last_name}`)}</h4>
      </div>

      {dupWarning && <div className="alert alert-warning">{dupWarning}</div>}
      {actionError && <div className="alert alert-danger">{actionError}</div>}

      <div className="row g-4">
        {/* ── Left column ── */}
        <div className="col-md-6 d-flex flex-column gap-4">
          {/* Profile Info */}
          <form
            id="profile-form"
            onSubmit={(e) => {
              e.preventDefault();
              void confirmProfile();
            }}
          >
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Profile Info</span>
                <div className="d-flex gap-2">
                  <button
                    type="button"
                    id="profileCancelBtn"
                    className="btn btn-sm btn-secondary"
                    style={{ display: profileEditing ? '' : 'none' }}
                    onClick={() => setProfileEditing(false)}
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    id="profileEditBtn"
                    className={`btn btn-sm ${profileEditing ? 'btn-warning' : 'btn-success'}`}
                    onClick={() => (profileEditing ? void confirmProfile() : startProfileEdit())}
                  >
                    {profileEditing ? 'Confirm' : 'Edit'}
                  </button>
                </div>
              </div>
              <div className="card-body">
                {/* View mode */}
                <div id="profile-view" style={{ display: profileEditing ? 'none' : '' }}>
                  {viewRows.map(([lbl, val]) => (
                    <div className="d-flex py-1 border-bottom" key={lbl}>
                      <div className="text-muted small" style={{ minWidth: 160 }}>{lbl}</div>
                      <div>{val}</div>
                    </div>
                  ))}
                  <div className="d-flex py-1 border-bottom">
                    <div className="text-muted small" style={{ minWidth: 160 }}>Account Type</div>
                    <div>
                      {s.student_type.charAt(0).toUpperCase() + s.student_type.slice(1)}
                      {ACCT_TIPS[s.student_type] && (
                        <span className="text-muted ms-1" title={ACCT_TIPS[s.student_type]}>ⓘ</span>
                      )}
                    </div>
                  </div>
                  <div className="d-flex py-1 border-bottom">
                    <div className="text-muted small" style={{ minWidth: 160 }}>Active Status</div>
                    <div>
                      {s.active ? (
                        <span className="badge bg-success" title="Active: attended class in the last 3 months">Active</span>
                      ) : (
                        <span className="badge bg-secondary" title="Inactive: no attendance in the last 3 months">Inactive</span>
                      )}
                      {ovVal !== 'auto' && (
                        <span className="badge bg-warning text-dark ms-1" title="Override: active/inactive status manually set by admin">
                          Override
                        </span>
                      )}
                    </div>
                  </div>
                  <div className="d-flex py-1 border-bottom">
                    <div className="text-muted small" style={{ minWidth: 160 }}>Waiver</div>
                    <div>
                      {s.injury_waiver ? (
                        <>
                          <span className="text-success">✓</span>
                          {s.injury_waiver_date && <span className="ms-1">{fmtDate(s.injury_waiver_date)}</span>}
                          <a href={`waiver_view.php?student_id=${id}`} className="btn btn-sm btn-outline-secondary ms-2">View</a>
                        </>
                      ) : (
                        <>
                          <span className="text-muted">Not completed</span>
                          <a href={`waiver_view.php?student_id=${id}`} className="btn btn-sm btn-success ms-2">+ Enter Waiver</a>
                        </>
                      )}
                    </div>
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
                {/* Edit mode — always in the DOM (specs query hidden fields) */}
                <div id="profile-edit" className="row g-3" style={{ display: profileEditing ? '' : 'none' }}>
                  <ProfileFields form={profileForm} setForm={setProfileForm} withStatus />
                </div>
              </div>
            </div>
          </form>

          {/* Attendance */}
          <div id="att-card" className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Recent Attendance</span>
              {(data.attendance ?? []).length > 0 && (
                <div className="d-flex gap-2">
                  <button
                    type="button"
                    className="btn btn-sm btn-secondary"
                    id="attCancelBtn"
                    style={{ display: attEditing ? '' : 'none' }}
                    onClick={() => {
                      setAttEditing(false);
                      setAttPresent(new Set((data.attendance ?? []).filter((a) => a.present).map((a) => a.session_id)));
                    }}
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    className={`btn btn-sm ${attEditing ? 'btn-warning' : 'btn-success'}`}
                    id="attEditBtn"
                    onClick={() => {
                      if (!attEditing) {
                        setAttEditing(true);
                      } else {
                        void post({ action: 'update_attendance', att_present: [...attPresent] }).then((ok) => {
                          if (ok) setAttEditing(false);
                        });
                      }
                    }}
                  >
                    {attEditing ? 'Confirm' : 'Edit'}
                  </button>
                </div>
              )}
            </div>
            <div className="card-body p-0" style={{ maxHeight: 320, overflowY: 'auto' }}>
              {(data.attendance ?? []).length === 0 ? (
                <p className="p-3 text-muted">No classes recorded.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <tbody>
                      {(data.attendance ?? []).map((a) => (
                        <tr key={a.session_id}>
                          <td>
                            <a
                              href={`../instructor/attendance.php?date=${a.session_date}`}
                              className="text-primary text-decoration-none"
                            >
                              {fmtDateLong(a.session_date)}
                            </a>
                          </td>
                          <td>
                            {(attEditing ? attPresent.has(a.session_id) : a.present) && (
                              <span className="badge bg-success">Present</span>
                            )}
                            <span className="att-edit ms-2" style={{ display: attEditing ? '' : 'none' }}>
                              <input
                                type="checkbox"
                                className="form-check-input"
                                name="att_present[]"
                                value={a.session_id}
                                checked={attPresent.has(a.session_id)}
                                onChange={() =>
                                  setAttPresent((prev) => {
                                    const next = new Set(prev);
                                    if (next.has(a.session_id)) next.delete(a.session_id);
                                    else next.add(a.session_id);
                                    return next;
                                  })
                                }
                              />
                            </span>
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

        {/* ── Right column ── */}
        <div className="col-md-6 d-flex flex-column gap-3">
          {/* Payment History */}
          <div id="pay-card" className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Payment History</span>
              <div className="d-flex gap-2">
                {(data.payments ?? []).length > 0 && (
                  <button
                    id="payEditToggle"
                    type="button"
                    className={`btn btn-sm ${payEditing ? 'btn-warning' : 'btn-success'}`}
                    onClick={() => {
                      setPayEditing((v) => {
                        if (v) setPayEditRows(new Set());
                        return !v;
                      });
                    }}
                  >
                    {payEditing ? 'Done' : 'Edit'}
                  </button>
                )}
                <button type="button" className="btn btn-sm btn-success" onClick={() => setPayAddOpen((v) => !v)}>
                  + Add Payment
                </button>
              </div>
            </div>
            <div id="pay-add-box" style={{ display: payAddOpen ? 'block' : 'none' }}>
              <div className="card-body border-bottom pb-3">
                <AddPaymentForm
                  onCancel={() => setPayAddOpen(false)}
                  onSave={async (form) => {
                    setActionError('');
                    try {
                      const res = await apiPost<{ saved: boolean; dup_count: number }>('/admin/student_edit.php', {
                        id,
                        action: 'add_payment',
                        ...form,
                      });
                      setDupWarning(
                        res.dup_count > 0
                          ? `Heads up: this student now has ${res.dup_count} tuition payments recorded for that month. If this was accidental, delete the extra one in the Payments section.`
                          : '',
                      );
                      setPayAddOpen(false);
                      load();
                    } catch (e: unknown) {
                      setActionError(e instanceof ApiError ? e.message : 'Could not add the payment.');
                    }
                  }}
                />
              </div>
            </div>
            <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
              {(data.payments ?? []).length === 0 ? (
                <p className="p-3 text-muted">No payments on record.</p>
              ) : (
                <div className="table-responsive">
                  <table id="payTable" className={`table table-sm table-hover mb-0 align-middle${payEditing ? ' editing' : ''}`}>
                    <thead className="table-light">
                      <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Method</th>
                        <th className="text-end">Amount</th>
                        <th className="pay-action-col" />
                      </tr>
                    </thead>
                    <tbody>
                      {(data.payments ?? []).map((p) => (
                        <PayRows
                          key={`${p.is_donation ? 'd' : 'p'}${p.id}`}
                          p={p}
                          editOpen={payEditRows.has(p.id)}
                          onToggle={() => togglePayRow(p.id)}
                          onDelete={() => {
                            if (window.confirm('Delete this payment?')) {
                              void post({ action: 'delete_payment', payment_id: p.id });
                            }
                          }}
                          onSave={async (form) => {
                            const ok = await post({ action: 'edit_payment', payment_id: p.id, ...form });
                            if (ok) togglePayRow(p.id);
                          }}
                        />
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          {/* Rank History */}
          <div id="rank-card" className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Rank History</span>
              <div className="d-flex gap-2">
                {(data.ranks ?? []).length > 0 && (
                  <button
                    id="rankEditToggle"
                    type="button"
                    className={`btn btn-sm ${rankEditing ? 'btn-danger' : 'btn-success'}`}
                    onClick={() => {
                      if (!rankEditing) {
                        setRankEditing(true);
                      } else {
                        const rank_updates = Object.entries(rankForm).map(([sr_id, u]) => ({
                          sr_id: Number(sr_id),
                          rank_id: u.rank_id,
                          achieved_date: u.achieved_date,
                        }));
                        void post({ action: 'update_ranks', rank_updates }).then((ok) => {
                          if (ok) setRankEditing(false);
                        });
                      }
                    }}
                  >
                    {rankEditing ? 'Done' : 'Edit'}
                  </button>
                )}
                <button type="button" className="btn btn-sm btn-success" onClick={() => setRankAddOpen((v) => !v)}>
                  + Record Rank
                </button>
              </div>
            </div>
            <div id="rank-add-box" style={{ display: rankAddOpen ? 'block' : 'none' }}>
              <div className="card-body border-bottom pb-3">
                <AddRankForm
                  allRanks={data.all_ranks}
                  onCancel={() => setRankAddOpen(false)}
                  onSave={async (form) => {
                    const ok = await post({ action: 'add_rank', ...form });
                    if (ok) setRankAddOpen(false);
                  }}
                />
              </div>
            </div>
            <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
              {(data.ranks ?? []).length === 0 ? (
                <p className="p-3 text-muted">No ranks recorded.</p>
              ) : (
                <div className="table-responsive">
                  <table id="rankTable" className={`table table-sm mb-0${rankEditing ? ' editing' : ''}`}>
                    <thead className="table-light">
                      <tr><th>Rank</th><th>Date Achieved</th><th className="rank-delete-col" /></tr>
                    </thead>
                    <tbody>
                      {(data.ranks ?? []).map((r, i) => (
                        <tr key={r.sr_id} className={i === 0 ? 'table-purple' : ''}>
                          <td>
                            <span className="rank-view-cell" style={{ display: rankEditing ? 'none' : '' }}>
                              {r.kyu_dan} — {r.name}
                            </span>
                            <select
                              name={`rank_updates[${r.sr_id}][rank_id]`}
                              className="form-select form-select-sm rank-edit-cell"
                              style={{ display: rankEditing ? '' : 'none', width: 'auto', maxWidth: 160 }}
                              value={rankForm[r.sr_id]?.rank_id ?? r.rank_id}
                              onChange={(e) =>
                                setRankForm({
                                  ...rankForm,
                                  [r.sr_id]: { ...(rankForm[r.sr_id] ?? { achieved_date: r.achieved_date ?? '' }), rank_id: Number(e.target.value) },
                                })
                              }
                            >
                              {data.all_ranks.map((ar) => (
                                <option key={ar.id} value={ar.id}>{ar.kyu_dan} — {ar.name}</option>
                              ))}
                            </select>
                          </td>
                          <td>
                            <span className="rank-view-cell" style={{ display: rankEditing ? 'none' : '' }}>
                              {r.achieved_date ? fmtDate(r.achieved_date) : '—'}
                            </span>
                            <input
                              type="date"
                              name={`rank_updates[${r.sr_id}][achieved_date]`}
                              className="form-control form-control-sm rank-edit-cell"
                              style={{ display: rankEditing ? '' : 'none', width: 'auto' }}
                              value={rankForm[r.sr_id]?.achieved_date ?? r.achieved_date ?? ''}
                              onChange={(e) =>
                                setRankForm({
                                  ...rankForm,
                                  [r.sr_id]: { ...(rankForm[r.sr_id] ?? { rank_id: r.rank_id }), achieved_date: e.target.value },
                                })
                              }
                            />
                          </td>
                          <td className="rank-delete-col">
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger py-0"
                              onClick={() => {
                                if (window.confirm('Delete this rank?')) {
                                  void post({ action: 'delete_rank', sr_id: r.sr_id });
                                }
                              }}
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

          {/* Belt Test History */}
          <div id="bt-card" className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Belt Test History</span>
              <div className="d-flex gap-2">
                {(data.belt_tests ?? []).length > 0 && (
                  <button
                    id="btEditToggle"
                    type="button"
                    className={`btn btn-sm ${btEditing ? 'btn-danger' : 'btn-success'}`}
                    onClick={() => setBtEditing((v) => !v)}
                  >
                    {btEditing ? 'Done' : 'Edit'}
                  </button>
                )}
                <a
                  href={`../instructor/belt_test_edit.php?student_id=${id}&ref_pid=${id}`}
                  className="btn btn-sm btn-success"
                >
                  + New Test
                </a>
              </div>
            </div>
            <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
              {(data.belt_tests ?? []).length === 0 ? (
                <p className="p-3 text-muted">No belt tests on record.</p>
              ) : (
                <div id="btList" className={btEditing ? 'editing' : ''}>
                  {(data.belt_tests ?? []).map((bt) => (
                    <div className="border-bottom px-3 py-2" key={bt.id}>
                      <div className={`bt-row-view-${bt.id} d-flex align-items-center gap-3 flex-wrap`}>
                        <span className="text-nowrap">{fmtDate(bt.test_date)}</span>
                        <span className="flex-grow-1">
                          <a
                            href={`../instructor/belt_test_edit.php?id=${bt.id}&ref_pid=${id}`}
                            className="text-primary text-decoration-none"
                          >
                            {bt.kyu_dan}
                          </a>
                        </span>
                        {bt.score !== null ? (
                          <span className={`badge ${bt.result === 'pass' ? 'bg-success' : 'bg-danger'}`}>{bt.score}%</span>
                        ) : (
                          <span className="badge bg-secondary">Pending</span>
                        )}
                        <span>Fee {bt.fee_paid && <span className="text-success">✓</span>}</span>
                        <span>
                          Passed{' '}
                          {bt.result === 'pass' ? (
                            <span className="text-success">✓</span>
                          ) : bt.result === 'fail' ? (
                            <span className="text-danger">✗</span>
                          ) : (
                            <span className="text-muted">—</span>
                          )}
                        </span>
                        <div className="d-flex gap-2 ms-auto">
                          <span className="bt-delete-btn">
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger py-0"
                              onClick={() => {
                                if (window.confirm('Delete this belt test?')) {
                                  void post({ action: 'delete_belt_test', bt_id: bt.id });
                                }
                              }}
                            >
                              ✕
                            </button>
                          </span>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* Exempt */}
          <div id="pw-card" className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Exempt</span>
              <div className="d-flex gap-2">
                {(data.payment_waivers ?? []).length > 0 && (
                  <button
                    id="pwEditToggle"
                    type="button"
                    className={`btn btn-sm ${pwEditing ? 'btn-warning' : 'btn-success'}`}
                    onClick={() => {
                      setPwEditing((v) => {
                        if (v) setPwEditRows(new Set());
                        return !v;
                      });
                    }}
                  >
                    {pwEditing ? 'Done' : 'Edit'}
                  </button>
                )}
                <button type="button" className="btn btn-sm btn-success" onClick={() => setPwAddOpen((v) => !v)}>
                  + Add Exemption
                </button>
              </div>
            </div>
            <div id="pw-add-box" style={{ display: pwAddOpen ? 'block' : 'none' }}>
              <div className="card-body border-bottom pb-3">
                <AddExemptionForm
                  onCancel={() => setPwAddOpen(false)}
                  onSave={async (form) => {
                    const ok = await post({ action: 'add_waiver', ...form });
                    if (ok) setPwAddOpen(false);
                  }}
                />
              </div>
            </div>
            <div className={`card-body${(data.payment_waivers ?? []).length ? ' p-0' : ''}`} style={{ maxHeight: 260, overflowY: 'auto' }}>
              {(data.payment_waivers ?? []).length === 0 ? (
                <p className="text-muted mb-0">No exemptions on record.</p>
              ) : (
                <div className="table-responsive">
                  <table id="pwTable" className={`table table-sm table-hover mb-0 align-middle${pwEditing ? ' editing' : ''}`}>
                    <tbody>
                      {(data.payment_waivers ?? []).map((pw) => (
                        <PwRows
                          key={pw.id}
                          pw={pw}
                          editOpen={pwEditRows.has(pw.id)}
                          onToggle={() => togglePwRow(pw.id)}
                          onDelete={() => {
                            if (window.confirm('Delete this waiver?')) {
                              void post({ action: 'delete_waiver', waiver_id: pw.id });
                            }
                          }}
                          onSave={async (form) => {
                            const ok = await post({ action: 'edit_waiver', waiver_id: pw.id, ...form });
                            if (ok) togglePwRow(pw.id);
                          }}
                        />
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          {/* Guardian / Children */}
          <div id="guardian-card" className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>{data.is_guardian_type ? 'Linked Children' : 'Guardian / Parent'}</span>
              <div className="d-flex gap-2">
                {(data.guardian_links ?? []).length > 0 && (
                  <button
                    id="guardianEditToggle"
                    type="button"
                    className={`btn btn-sm ${guardianEditing ? 'btn-warning' : 'btn-success'}`}
                    onClick={() => setGuardianEditing((v) => !v)}
                  >
                    {guardianEditing ? 'Done' : 'Edit'}
                  </button>
                )}
                <button type="button" className="btn btn-sm btn-success" onClick={() => setGuardianAddOpen((v) => !v)}>
                  + Link
                </button>
              </div>
            </div>
            <div id="guardian-add-box" style={{ display: guardianAddOpen ? 'block' : 'none' }}>
              <div className="card-body border-bottom pb-3">
                <form
                  className="d-flex gap-2 align-items-center flex-wrap"
                  onSubmit={(e) => {
                    e.preventDefault();
                    if (guardianPick) {
                      void post({ action: 'add_guardian', guardian_student_id: Number(guardianPick) }).then((ok) => {
                        if (ok) {
                          setGuardianAddOpen(false);
                          setGuardianPick('');
                        }
                      });
                    }
                  }}
                >
                  {(data.guardian_candidates ?? []).length > 0 ? (
                    <>
                      <select
                        name="guardian_student_id"
                        className="form-select form-select-sm"
                        style={{ maxWidth: 240 }}
                        required
                        value={guardianPick}
                        onChange={(e) => setGuardianPick(e.target.value)}
                      >
                        <option value="">— select —</option>
                        {(data.guardian_candidates ?? []).map((gc) => (
                          <option key={gc.id} value={gc.id}>{personName(gc.name)}</option>
                        ))}
                      </select>
                      <button type="submit" className="btn btn-sm btn-success">Add</button>
                    </>
                  ) : (
                    <span className="text-muted small">
                      No {data.is_guardian_type ? 'child' : 'parent'} records available to link.
                    </span>
                  )}
                  <button type="button" className="btn btn-sm btn-secondary" onClick={() => setGuardianAddOpen(false)}>
                    Cancel
                  </button>
                </form>
              </div>
            </div>
            <div className={`card-body${(data.guardian_links ?? []).length ? ' p-0' : ''}`} style={{ maxHeight: 260, overflowY: 'auto' }}>
              {(data.guardian_links ?? []).length === 0 ? (
                <p className="text-muted mb-0">None linked.</p>
              ) : (
                <div className="table-responsive">
                  <table id="guardianTable" className={`table table-sm table-hover mb-0 align-middle${guardianEditing ? ' editing' : ''}`}>
                    <tbody>
                      {(data.guardian_links ?? []).map((gl) => (
                        <tr key={gl.link_id}>
                          <td>
                            <a href={`student_edit.php?id=${gl.student_id}`} className="text-decoration-none">
                              {personName(gl.name)}
                            </a>
                          </td>
                          <td className="guardian-delete-col text-end">
                            <button
                              type="button"
                              className="btn btn-sm btn-outline-danger py-0"
                              onClick={() => {
                                if (window.confirm('Remove this link?')) {
                                  void post({ action: 'remove_guardian', guardian_link_id: gl.link_id });
                                }
                              }}
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
      </div>

      {/* ── Notes (full width) ── */}
      <div id="notes-card" className="card border-0 shadow-sm mt-4">
        <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>Student Notes</span>
          <div className="d-flex gap-2">
            {(data.notes ?? []).length > 0 && (
              <button
                type="button"
                id="notesEditBtn"
                className={`btn btn-sm ${notesEditing ? 'btn-danger' : 'btn-success'}`}
                onClick={() => setNotesEditing((v) => !v)}
              >
                {notesEditing ? 'Done' : 'Edit'}
              </button>
            )}
          </div>
        </div>

        <div id="addNoteBox">
          <div className="card-body border-bottom pb-3">
            <form
              id="addNoteForm"
              onSubmit={(e) => {
                e.preventDefault();
                if (noteText.trim()) {
                  void post({ action: 'add_note', note_content: noteText }).then((ok) => {
                    if (ok) setNoteText('');
                  });
                }
              }}
            >
              <textarea
                name="note_content"
                id="addNoteText"
                className="form-control form-control-sm mb-2"
                rows={3}
                placeholder="Add a note…"
                required
                value={noteText}
                onChange={(e) => setNoteText(e.target.value)}
              />
              <div className="d-flex gap-2">
                <button type="submit" className="btn btn-sm btn-primary">Save Note</button>
              </div>
            </form>
          </div>
        </div>

        <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
          {(data.notes ?? []).length === 0 ? (
            <p className="p-3 text-muted">No notes yet.</p>
          ) : (
            <div id="notesContainer" className={notesEditing ? 'notes-editing' : ''}>
              {(data.notes ?? []).map((n) => (
                <StudentNoteEntry
                  key={n.id}
                  n={n}
                  editOpen={noteEditOpen.has(n.id)}
                  onToggleEdit={() => toggleNoteEdit(n.id)}
                  onDelete={() => {
                    if (window.confirm('Delete this note?')) {
                      void post({ action: 'delete_note', note_id: n.id });
                    }
                  }}
                  onSave={async (content) => {
                    const ok = await post({ action: 'edit_note', note_id: n.id, note_content: content });
                    if (ok) toggleNoteEdit(n.id);
                  }}
                />
              ))}
            </div>
          )}
        </div>
      </div>

      <div className="mt-4 text-end">
        <button type="button" className="btn btn-outline-danger" onClick={() => void deleteProfile()}>
          Delete Profile
        </button>
      </div>
    </>
  );
}

// ── Sub-forms ────────────────────────────────────────────────────

function NewStudentForm({
  onSubmit,
  actionError,
}: {
  onSubmit: (form: ProfileForm) => Promise<void>;
  actionError: string;
}) {
  const [form, setForm] = useState<ProfileForm>(emptyProfile);
  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4">
        <h4 className="mb-0">New Student</h4>
      </div>
      {actionError && <div className="alert alert-danger">{actionError}</div>}
      <div className="row g-4">
        <div className="col-md-6">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void onSubmit(form);
            }}
          >
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold">Profile Info</div>
              <div className="card-body">
                <div className="row g-3">
                  <ProfileFields form={form} setForm={setForm} withStatus={false} />
                  <div className="col-12">
                    <button type="submit" className="btn btn-primary">Add to Roster</button>
                  </div>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>
    </>
  );
}

function AddPaymentForm({
  onSave,
  onCancel,
}: {
  onSave: (form: Record<string, string>) => Promise<void>;
  onCancel: () => void;
}) {
  const [form, setForm] = useState({
    payment_date: today(),
    amount: '',
    payment_type: 'monthly_tuition',
    payment_method: 'paypal',
  });
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        void onSave(form);
      }}
    >
      <div className="row g-2 mb-2">
        <div className="col-6">
          <label className="form-label small">Date *</label>
          <input
            type="date"
            name="payment_date"
            className="form-control form-control-sm"
            required
            value={form.payment_date}
            onChange={(e) => setForm({ ...form, payment_date: e.target.value })}
          />
        </div>
        <div className="col-6">
          <label className="form-label small">Amount *</label>
          <input
            type="number"
            name="amount"
            className="form-control form-control-sm"
            step="0.01"
            min="0.01"
            placeholder="0.00"
            required
            value={form.amount}
            onChange={(e) => setForm({ ...form, amount: e.target.value })}
          />
        </div>
        <div className="col-6">
          <label className="form-label small">Type *</label>
          <select
            name="payment_type"
            className="form-select form-select-sm"
            value={form.payment_type}
            onChange={(e) => setForm({ ...form, payment_type: e.target.value })}
          >
            {PAY_TYPE_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div className="col-6">
          <label className="form-label small">Method *</label>
          <select
            name="payment_method"
            className="form-select form-select-sm"
            value={form.payment_method}
            onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
          >
            {METHOD_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
      </div>
      <div className="d-flex gap-2">
        <button type="submit" className="btn btn-sm btn-success">Save</button>
        <button type="button" className="btn btn-sm btn-secondary" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

function AddRankForm({
  allRanks,
  onSave,
  onCancel,
}: {
  allRanks: { id: number; name: string; kyu_dan: string }[];
  onSave: (form: { new_rank_id: number; new_rank_date: string }) => Promise<void>;
  onCancel: () => void;
}) {
  const [rankId, setRankId] = useState('');
  const [date, setDate] = useState(today());
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        if (rankId) void onSave({ new_rank_id: Number(rankId), new_rank_date: date });
      }}
    >
      <div className="row g-2 mb-2">
        <div className="col-7">
          <label className="form-label small">Rank *</label>
          <select
            name="new_rank_id"
            className="form-select form-select-sm"
            required
            value={rankId}
            onChange={(e) => setRankId(e.target.value)}
          >
            <option value="">— select —</option>
            {allRanks.map((ar) => (
              <option key={ar.id} value={ar.id}>{ar.kyu_dan} — {ar.name}</option>
            ))}
          </select>
        </div>
        <div className="col-5">
          <label className="form-label small">Date Achieved</label>
          <input
            type="date"
            name="new_rank_date"
            className="form-control form-control-sm"
            value={date}
            onChange={(e) => setDate(e.target.value)}
          />
        </div>
      </div>
      <div className="d-flex gap-2">
        <button type="submit" className="btn btn-sm btn-success">Save</button>
        <button type="button" className="btn btn-sm btn-secondary" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

function AddExemptionForm({
  onSave,
  onCancel,
}: {
  onSave: (form: Record<string, string>) => Promise<void>;
  onCancel: () => void;
}) {
  const [form, setForm] = useState({ waiver_type: 'monthly_tuition', granted_date: today(), reason: '' });
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        void onSave(form);
      }}
    >
      <div className="row g-2 mb-2">
        <div className="col-7">
          <label className="form-label small">Type *</label>
          <select
            name="waiver_type"
            className="form-select form-select-sm"
            required
            value={form.waiver_type}
            onChange={(e) => setForm({ ...form, waiver_type: e.target.value })}
          >
            {WAIVER_TYPE_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </div>
        <div className="col-5">
          <label className="form-label small">Date</label>
          <input
            type="date"
            name="granted_date"
            className="form-control form-control-sm"
            value={form.granted_date}
            onChange={(e) => setForm({ ...form, granted_date: e.target.value })}
          />
        </div>
        <div className="col-12">
          <label className="form-label small">Reason</label>
          <input
            type="text"
            name="reason"
            className="form-control form-control-sm"
            placeholder="Optional"
            value={form.reason}
            onChange={(e) => setForm({ ...form, reason: e.target.value })}
          />
        </div>
      </div>
      <div className="d-flex gap-2">
        <button type="submit" className="btn btn-sm btn-success">Save</button>
        <button type="button" className="btn btn-sm btn-secondary" onClick={onCancel}>Cancel</button>
      </div>
    </form>
  );
}

type PayRow = NonNullable<StudentEditData['payments']>[number];

function PayRows({
  p,
  editOpen,
  onToggle,
  onDelete,
  onSave,
}: {
  p: PayRow;
  editOpen: boolean;
  onToggle: () => void;
  onDelete: () => void;
  onSave: (form: Record<string, string>) => Promise<void>;
}) {
  const [form, setForm] = useState({
    payment_date: p.payment_date.slice(0, 10),
    payment_type: p.payment_type,
    payment_method: p.payment_method,
    amount: String(p.amount),
  });
  return (
    <>
      <tr className="pay-data-row">
        <td>{fmtDate(p.payment_date)}</td>
        <td>{paymentType(p.payment_type)}</td>
        <td>{Object.fromEntries(METHOD_OPTIONS)[p.payment_method] ?? p.payment_method}</td>
        <td className="text-end">${p.amount.toFixed(2)}</td>
        {p.is_donation ? (
          <td className="pay-action-col text-end text-nowrap">
            <a href="donations.php" className="btn btn-sm btn-outline-secondary py-0">Donations</a>
          </td>
        ) : (
          <td className="pay-action-col text-end text-nowrap">
            <button type="button" className="btn btn-sm btn-outline-primary py-0 me-1 toggle-pay-row-btn" data-id={p.id} onClick={onToggle}>
              Edit
            </button>
            <button type="button" className="btn btn-sm btn-outline-danger py-0" onClick={onDelete}>✕</button>
          </td>
        )}
      </tr>
      {!p.is_donation && (
        <tr id={`pay-edit-${p.id}`} className="pay-edit-row" style={{ display: editOpen ? '' : 'none' }}>
          <td colSpan={5}>
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
                  {PAY_TYPE_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
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
                  {METHOD_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              <div className="col-auto" style={{ width: 100 }}>
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
              <div className="col-auto">
                <button type="submit" className="btn btn-sm btn-success">Save</button>
                <button type="button" className="btn btn-sm btn-secondary toggle-pay-row-btn ms-1" data-id={p.id} onClick={onToggle}>
                  Cancel
                </button>
              </div>
            </form>
          </td>
        </tr>
      )}
    </>
  );
}

type PwRow = NonNullable<StudentEditData['payment_waivers']>[number];

function PwRows({
  pw,
  editOpen,
  onToggle,
  onDelete,
  onSave,
}: {
  pw: PwRow;
  editOpen: boolean;
  onToggle: () => void;
  onDelete: () => void;
  onSave: (form: Record<string, string>) => Promise<void>;
}) {
  const [form, setForm] = useState({
    waiver_type: pw.waiver_type,
    granted_date: pw.granted_date,
    reason: pw.reason ?? '',
  });
  return (
    <>
      <tr className="pw-data-row">
        <td>
          {paymentType(pw.waiver_type)}
          {pw.reason && <div className="text-muted small">{pw.reason}</div>}
        </td>
        <td className="text-nowrap">{fmtDate(pw.granted_date)}</td>
        <td className="pw-action-col text-end text-nowrap">
          <button type="button" className="btn btn-sm btn-outline-primary py-0 me-1 toggle-pw-row-btn" data-id={pw.id} onClick={onToggle}>
            Edit
          </button>
          <button type="button" className="btn btn-sm btn-outline-danger py-0" onClick={onDelete}>✕</button>
        </td>
      </tr>
      <tr id={`pw-edit-${pw.id}`} className="pw-edit-row" style={{ display: editOpen ? '' : 'none' }}>
        <td colSpan={3}>
          <form
            className="row g-2 align-items-end py-1"
            onSubmit={(e) => {
              e.preventDefault();
              void onSave(form);
            }}
          >
            <div className="col-auto">
              <label className="form-label small mb-1">Type</label>
              <select
                name="waiver_type"
                className="form-select form-select-sm"
                value={form.waiver_type}
                onChange={(e) => setForm({ ...form, waiver_type: e.target.value })}
              >
                {WAIVER_TYPE_OPTIONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
            <div className="col-auto" style={{ width: 160 }}>
              <label className="form-label small mb-1">Date</label>
              <input
                type="date"
                name="granted_date"
                className="form-control form-control-sm w-100"
                required
                value={form.granted_date}
                onChange={(e) => setForm({ ...form, granted_date: e.target.value })}
              />
            </div>
            <div className="col">
              <label className="form-label small mb-1">Reason</label>
              <input
                type="text"
                name="reason"
                className="form-control form-control-sm"
                placeholder="Optional"
                value={form.reason}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
              />
            </div>
            <div className="col-auto">
              <button type="submit" className="btn btn-sm btn-success">Save</button>
              <button type="button" className="btn btn-sm btn-secondary toggle-pw-row-btn ms-1" data-id={pw.id} onClick={onToggle}>
                Cancel
              </button>
            </div>
          </form>
        </td>
      </tr>
    </>
  );
}

type NoteRow = NonNullable<StudentEditData['notes']>[number];

function StudentNoteEntry({
  n,
  editOpen,
  onToggleEdit,
  onDelete,
  onSave,
}: {
  n: NoteRow;
  editOpen: boolean;
  onToggleEdit: () => void;
  onDelete: () => void;
  onSave: (content: string) => Promise<void>;
}) {
  const [content, setContent] = useState(n.content);
  return (
    <div className="border-bottom p-3" id={`note-wrap-${n.id}`}>
      <div className="d-flex justify-content-between align-items-start gap-2">
        <small className="text-muted">
          {fmtDateTime(n.created_at)} · <strong>{n.username ?? 'unknown'}</strong>
        </small>
        <div className="d-flex gap-1 flex-shrink-0">
          <button type="button" className="btn btn-sm btn-success py-0 note-edit-btn" data-id={n.id} onClick={onToggleEdit}>
            Edit
          </button>
          <span className="note-delete">
            <button type="button" className="btn btn-sm btn-outline-danger py-0" onClick={onDelete}>✕</button>
          </span>
        </div>
      </div>
      <p className={`mb-0 mt-1 note-view-${n.id}`} style={{ display: editOpen ? 'none' : '', whiteSpace: 'pre-line' }}>
        {n.content}
      </p>
      <form
        className={`mt-2 note-edit-${n.id}`}
        style={{ display: editOpen ? '' : 'none' }}
        onSubmit={(e) => {
          e.preventDefault();
          if (content.trim()) void onSave(content);
        }}
      >
        <textarea
          name="note_content"
          className="form-control form-control-sm mb-2"
          rows={3}
          required
          value={content}
          onChange={(e) => setContent(e.target.value)}
        />
        <div className="d-flex gap-2">
          <button type="submit" className="btn btn-sm btn-primary">Save</button>
          <button type="button" className="btn btn-sm btn-secondary note-cancel-btn" data-id={n.id} onClick={onToggleEdit}>
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
}
