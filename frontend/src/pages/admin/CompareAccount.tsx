// Compare & Link Account — React port of admin/compare_account.php: the
// link-request banner, student picker, side-by-side login-vs-student tables
// with mismatch highlighting, and the Link These Accounts action.

import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { CompareAccountData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, fmtDateTime, personName } from '../../format';

const TYPE_LABELS: Record<string, string> = {
  new_guest: 'New Student',
  existing_student: 'Existing Student',
  parent: 'Parent',
};
const TYPE_COLOURS: Record<string, string> = {
  new_guest: 'bg-success',
  existing_student: 'bg-primary',
  parent: 'bg-info text-dark',
};

/** Highlight cell if values differ (PHP cmp_class). */
const cmpClass = (a: string | null, b: string | null): string =>
  (a ?? '').trim().toLowerCase() !== (b ?? '').trim().toLowerCase() ? 'table-warning' : '';

export default function CompareAccount() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const userId = Number(searchParams.get('user_id') ?? 0);
  const studentId = Number(searchParams.get('student_id') ?? 0);
  const linkRequestId = Number(searchParams.get('link_request_id') ?? 0);

  const [data, setData] = useState<CompareAccountData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState('');

  useEffect(() => {
    if (!userId) {
      navigate('/admin', { replace: true });
      return;
    }
    const qs = new URLSearchParams({ user_id: String(userId) });
    if (studentId) qs.set('student_id', String(studentId));
    if (linkRequestId) qs.set('link_request_id', String(linkRequestId));
    setData(null);
    apiGet<CompareAccountData>(`/admin/compare_account.php?${qs.toString()}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the account.'));
  }, [userId, studentId, linkRequestId, navigate]);

  if (!data || error) return <PageState error={error} loading />;

  const u = data.user;
  const s = data.student;
  const lr = data.link_request;

  const pickStudent = (value: string) => {
    if (!value) return;
    const next = new URLSearchParams(searchParams);
    next.set('student_id', value);
    setSearchParams(next);
  };

  const link = async () => {
    if (!s) return;
    if (!window.confirm(`Link ${u.username} to ${personName(`${s.first_name} ${s.last_name}`)}?`)) return;
    setActionError('');
    try {
      await apiPost('/admin/compare_account.php', {
        action: 'link',
        user_id: userId,
        student_id: s.id,
        link_request_id: linkRequestId,
      });
      navigate('/admin?linked=1');
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'Linking failed.');
    }
  };

  const dismiss = async () => {
    if (!window.confirm('Dismiss this request without linking?')) return;
    setActionError('');
    try {
      await apiPost('/admin/compare_account.php', { action: 'dismiss', link_request_id: linkRequestId });
      navigate('/admin');
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'Dismiss failed.');
    }
  };

  const userRows: [string, React.ReactNode, string][] = [
    ['First Name', u.first_name ? personName(u.first_name) : '—', s ? cmpClass(u.first_name, s.first_name) : ''],
    ['Last Name', u.last_name ? personName(u.last_name) : '—', s ? cmpClass(u.last_name, s.last_name) : ''],
    [
      'Date of Birth',
      u.date_of_birth ? fmtDate(u.date_of_birth) : '—',
      s ? cmpClass(u.date_of_birth, s.date_of_birth) : '',
    ],
    ['Email', u.email || '—', s ? cmpClass(u.email, s.email) : ''],
    ['Username', u.username, ''],
    ['Role', u.is_admin ? 'Admin' : 'User', ''],
    [
      'Status',
      u.active ? (
        <span className="badge bg-success">Active</span>
      ) : (
        <span className="badge bg-danger">Deactivated</span>
      ),
      '',
    ],
    [
      'Currently Linked To',
      data.existing_link ? (
        <a href={`../instructor/student_profile.php?id=${data.existing_link.id}`}>
          {personName(data.existing_link.name)}
        </a>
      ) : (
        <span className="text-muted">Not linked</span>
      ),
      '',
    ],
  ];

  const studentRows: [string, React.ReactNode, string][] = s
    ? [
        ['First Name', personName(s.first_name), cmpClass(u.first_name, s.first_name)],
        ['Last Name', personName(s.last_name), cmpClass(u.last_name, s.last_name)],
        ['Date of Birth', s.date_of_birth ? fmtDate(s.date_of_birth) : '—', cmpClass(u.date_of_birth, s.date_of_birth)],
        ['Email', s.email || '—', cmpClass(u.email, s.email)],
        ['Type', s.student_type, ''],
        ['Current Rank', s.current_rank ?? '—', ''],
        ['Last Attended', s.last_attended ? fmtDate(s.last_attended) : <span className="text-muted">Never</span>, ''],
        ['Registered', s.registration_date ? fmtDate(s.registration_date) : '—', ''],
        [
          'Waiver',
          s.injury_waiver ? (
            <span className="text-success">✓ Signed</span>
          ) : (
            <span className="text-danger">✗ Not signed</span>
          ),
          '',
        ],
        [
          'Currently Linked To',
          s.linked_user ? (
            <a href={`user_profile.php?id=${s.linked_user.id}`}>{s.linked_user.username}</a>
          ) : (
            <span className="text-muted">Not linked</span>
          ),
          '',
        ],
      ]
    : [];

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4">
        <a href="index.php" className="btn btn-outline-secondary btn-sm">← Dashboard</a>
        <h4 className="mb-0">Compare &amp; Link Account</h4>
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {lr && (
        <div className="alert alert-info d-flex justify-content-between align-items-start mb-4">
          <div>
            <span className={`badge ${TYPE_COLOURS[lr.request_type] ?? 'bg-secondary'} me-2`}>
              {TYPE_LABELS[lr.request_type] ??
                lr.request_type.charAt(0).toUpperCase() + lr.request_type.slice(1)}
            </span>
            <strong>{personName(`${u.first_name ?? ''} ${u.last_name ?? ''}`)}</strong> submitted a link request{' '}
            <span className="text-muted small">({fmtDateTime(lr.created_at)})</span>
            {lr.notes && <div className="mt-1 small fst-italic">"{lr.notes}"</div>}
          </div>
          <div className="ms-3 flex-shrink-0" id="dismissLinkForm">
            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => void dismiss()}>
              Dismiss
            </button>
          </div>
        </div>
      )}

      {/* Student picker */}
      <div className="card border-0 shadow-sm mb-4">
        <div className="card-header bg-white fw-semibold">Select a Student Record to Compare</div>
        <div className="card-body py-3">
          <form id="studentPickerForm" className="d-flex gap-2 align-items-end flex-wrap" onSubmit={(e) => e.preventDefault()}>
            <div>
              <label className="form-label small mb-1">Student Record</label>
              <select
                name="student_id"
                id="studentPicker"
                className="form-select form-select-sm"
                style={{ minWidth: 260 }}
                value={studentId || ''}
                onChange={(e) => pickStudent(e.target.value)}
              >
                <option value="">— pick a student —</option>
                {data.students.map((st) => (
                  <option key={st.id} value={st.id}>
                    {personName(`${st.first_name} ${st.last_name}`)} ({st.student_type})
                  </option>
                ))}
              </select>
            </div>
            <a
              href={`student_edit.php?prefill_first=${encodeURIComponent(u.first_name ?? '')}&prefill_last=${encodeURIComponent(u.last_name ?? '')}&prefill_email=${encodeURIComponent(u.email ?? '')}`}
              className="btn btn-outline-secondary btn-sm"
            >
              + Create New Student Record
            </a>
          </form>
        </div>
      </div>

      {/* Side-by-side comparison */}
      <div className="row g-4 mb-4">
        <div className="col-md-6">
          <div className="card border-0 shadow-sm h-100">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Login Account</span>
              <a href={`user_profile.php?id=${userId}`} className="btn btn-sm btn-outline-secondary">View</a>
            </div>
            <div className="card-body p-0">
              <div className="table-responsive">
                <table className="table table-sm mb-0 compare-table">
                  <tbody>
                    {userRows.map(([lbl, val, cls]) => (
                      <tr key={lbl} className={cls}>
                        <td>{lbl}</td>
                        <td>{val}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div className="col-md-6">
          <div className="card border-0 shadow-sm h-100">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Student Record</span>
              {s && (
                <a href={`../instructor/student_profile.php?id=${s.id}`} className="btn btn-sm btn-outline-secondary">
                  View
                </a>
              )}
            </div>
            <div className="card-body p-0">
              {!s ? (
                <p className="p-3 text-muted mb-0">Select a student record above to compare.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm mb-0 compare-table">
                    <tbody>
                      {studentRows.map(([lbl, val, cls]) => (
                        <tr key={lbl} className={cls}>
                          <td>{lbl}</td>
                          <td>{val}</td>
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

      {s && (
        <div className="d-flex gap-3 align-items-center">
          <button type="button" id="linkAccountsForm" className="btn btn-primary px-4" onClick={() => void link()}>
            Link These Accounts
          </button>
          <a href="index.php" className="btn btn-outline-secondary">Cancel</a>
          {(data.existing_link || s.linked_user) && (
            <span className="text-warning small">
              ⚠ One or both sides are already linked — linking here will replace the existing link.
            </span>
          )}
        </div>
      )}
    </>
  );
}
