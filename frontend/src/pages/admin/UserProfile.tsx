// User Profile — React port of admin/user_profile.php: the Account Details
// card with the Edit → Confirm / Cancel toggle (same #accountEditBtn /
// #account-view / #account-edit ids the specs address), linked-roster card,
// collapsible password reset, activate/deactivate, and account delete.

import { useCallback, useEffect, useState } from 'react';
import { useParams, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { AdminUserProfile } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, fmtDateTime, personName } from '../../format';

const ROLE_BADGES: Record<string, string> = {
  admin: 'bg-danger',
  instructor: 'bg-warning text-dark',
  student: 'bg-primary',
  parent: 'bg-info text-dark',
  guest: 'bg-secondary',
};

const FLASH: Record<string, string> = {
  saved: 'Changes saved.',
  password: 'Password updated.',
  linked: 'Account linked to roster entry.',
  unlinked: 'Account unlinked from roster.',
};

interface AccountForm {
  first_name: string;
  last_name: string;
  date_of_birth: string;
  username: string;
  email: string;
  is_admin: boolean;
}

export default function UserProfile() {
  const { id } = useParams();
  const userId = Number(id);
  const [searchParams] = useSearchParams();

  const [data, setData] = useState<AdminUserProfile | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState('');
  const [actionError, setActionError] = useState('');

  // ?msg= flash from redirects — reactive, because a hash-only navigation
  // (same route, new query) re-renders without remounting this component.
  const urlMsg = FLASH[searchParams.get('msg') ?? ''] ?? '';
  useEffect(() => {
    if (urlMsg) setMsg(urlMsg);
  }, [urlMsg]);

  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<AccountForm | null>(null);
  const [pwOpen, setPwOpen] = useState(false);
  const [newPassword, setNewPassword] = useState('');
  const [linkStudentId, setLinkStudentId] = useState('');

  const load = useCallback(() => {
    apiGet<AdminUserProfile>(`/admin/user_profile.php?id=${userId}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the account.'));
  }, [userId]);

  useEffect(load, [load]);

  if (!data || error) return <PageState error={error} loading />;

  const u = data.user;
  const isSelf = u.id === data.current_user_id;
  const effectiveRole = u.is_admin ? 'admin' : (u.student_type ?? 'student');

  const startEdit = () => {
    setForm({
      first_name: u.first_name ?? '',
      last_name: u.last_name ?? '',
      date_of_birth: u.date_of_birth ?? '',
      username: u.username,
      email: u.email ?? '',
      is_admin: u.is_admin,
    });
    setEditing(true);
  };

  const run = async (payload: Record<string, unknown>, successMsg: string) => {
    setActionError('');
    try {
      await apiPost('/admin/user_profile.php', { id: userId, ...payload });
      setMsg(successMsg);
      load();
      return true;
    } catch (e: unknown) {
      setMsg('');
      setActionError(e instanceof ApiError ? e.message : 'The action failed.');
      return false;
    }
  };

  const confirmEdit = async () => {
    if (!form) return;
    const ok = await run({ action: 'update_account', ...form }, 'Changes saved.');
    if (ok) setEditing(false);
  };

  const deleteUser = async () => {
    if (
      !window.confirm(
        'Delete this login account?\n\nThe student roster entry and all their history (attendance, belt tests, payments) will be kept. Only the login account will be removed.',
      )
    ) {
      return;
    }
    setActionError('');
    try {
      await apiPost('/admin/user_profile.php', { id: userId, action: 'delete_user' });
      window.location.href = 'users.php?msg=deleted';
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'Delete failed.');
    }
  };

  const viewRows: [string, string][] = [
    ['First Name', u.first_name ? personName(u.first_name) : '—'],
    ['Last Name', u.last_name ? personName(u.last_name) : '—'],
    ['Date of Birth', u.date_of_birth ? fmtDate(u.date_of_birth) : '—'],
    ['Username', u.username],
    ['Email', u.email || '—'],
    ['Role', effectiveRole.charAt(0).toUpperCase() + effectiveRole.slice(1)],
    ['Account Created', fmtDate(u.created_at)],
    ['Last Login', u.last_login ? fmtDateTime(u.last_login) : 'Never'],
  ];

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4">
        <h4 className="mb-0">
          {u.username}
          <span className={`badge ${ROLE_BADGES[effectiveRole] ?? 'bg-secondary'} ms-1`}>
            {effectiveRole.charAt(0).toUpperCase() + effectiveRole.slice(1)}
          </span>
          {!u.active && <span className="badge bg-danger ms-1">Deactivated</span>}
        </h4>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {actionError && <div className="alert alert-danger">{actionError}</div>}

      <div className="row g-4" style={{ maxWidth: 700 }}>
        {/* Account Details */}
        <div className="col-12">
          <form
            id="account-form"
            onSubmit={(e) => {
              e.preventDefault();
              void confirmEdit();
            }}
          >
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Account Details</span>
                <div className="d-flex gap-2">
                  <button
                    type="button"
                    id="accountCancelBtn"
                    className="btn btn-sm btn-secondary"
                    style={{ display: editing ? '' : 'none' }}
                    onClick={() => setEditing(false)}
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    id="accountEditBtn"
                    className={`btn btn-sm ${editing ? 'btn-warning' : 'btn-success'}`}
                    onClick={() => (editing ? void confirmEdit() : startEdit())}
                  >
                    {editing ? 'Confirm' : 'Edit'}
                  </button>
                </div>
              </div>
              <div className="card-body">
                {/* View */}
                <div id="account-view" className="row g-2" style={{ display: editing ? 'none' : '' }}>
                  {viewRows.map(([lbl, val]) => (
                    <div className="col-6" key={lbl}>
                      <div className="text-muted small">{lbl}</div>
                      <div>{val}</div>
                    </div>
                  ))}
                </div>
                {/* Edit */}
                <div id="account-edit" className="row g-3" style={{ display: editing ? '' : 'none' }}>
                  {form && (
                    <>
                      <div className="col-6">
                        <label className="form-label">First Name</label>
                        <input
                          type="text"
                          name="first_name"
                          className="form-control"
                          value={form.first_name}
                          onChange={(e) => setForm({ ...form, first_name: e.target.value })}
                        />
                      </div>
                      <div className="col-6">
                        <label className="form-label">Last Name</label>
                        <input
                          type="text"
                          name="last_name"
                          className="form-control"
                          value={form.last_name}
                          onChange={(e) => setForm({ ...form, last_name: e.target.value })}
                        />
                      </div>
                      <div className="col-6">
                        <label className="form-label">Date of Birth</label>
                        <input
                          type="date"
                          name="date_of_birth"
                          className="form-control"
                          value={form.date_of_birth}
                          onChange={(e) => setForm({ ...form, date_of_birth: e.target.value })}
                        />
                      </div>
                      <div className="col-6">
                        <label className="form-label">Username *</label>
                        <input
                          type="text"
                          name="username"
                          className="form-control"
                          required
                          value={form.username}
                          onChange={(e) => setForm({ ...form, username: e.target.value })}
                        />
                      </div>
                      <div className="col-6">
                        <label className="form-label">Email</label>
                        <input
                          type="email"
                          name="email"
                          className="form-control"
                          value={form.email}
                          onChange={(e) => setForm({ ...form, email: e.target.value })}
                        />
                      </div>
                      <div className="col-6 d-flex align-items-end pb-1">
                        <div className="form-check">
                          <input
                            type="checkbox"
                            className="form-check-input"
                            name="is_admin"
                            id="isAdminChk"
                            checked={form.is_admin}
                            disabled={isSelf}
                            onChange={(e) => setForm({ ...form, is_admin: e.target.checked })}
                          />
                          <label className="form-check-label" htmlFor="isAdminChk">Administrator</label>
                        </div>
                      </div>
                    </>
                  )}
                </div>
              </div>
            </div>
          </form>
        </div>

        {/* Linked Roster Entry */}
        <div className="col-12">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Linked Roster Entry</div>
            <div className="card-body">
              {u.student_id !== null ? (
                <div className="d-flex align-items-center justify-content-between flex-wrap gap-2">
                  <div>
                    <div>{u.student_name}</div>
                    <div className="text-muted small">
                      {(u.student_type ?? '').charAt(0).toUpperCase() + (u.student_type ?? '').slice(1)}
                    </div>
                  </div>
                  <div className="d-flex gap-2">
                    <a href={`#/admin/student-edit?id=${u.student_id}`} className="btn btn-sm btn-outline-primary">
                      Edit Roster Entry
                    </a>
                    <button
                      className="btn btn-sm btn-outline-danger"
                      onClick={() => {
                        if (window.confirm('Unlink this account from the roster entry?')) {
                          void run({ action: 'unlink' }, 'Account unlinked from roster.');
                        }
                      }}
                    >
                      Unlink
                    </button>
                  </div>
                </div>
              ) : data.unlinked.length > 0 ? (
                <>
                  <p className="text-muted small mb-2">This account is not linked to any roster entry.</p>
                  <form
                    className="d-flex gap-2 align-items-center flex-wrap"
                    onSubmit={(e) => {
                      e.preventDefault();
                      if (linkStudentId) {
                        window.location.href = `compare_account.php?user_id=${userId}&student_id=${linkStudentId}`;
                      }
                    }}
                  >
                    <select
                      name="student_id"
                      className="form-select form-select-sm"
                      style={{ maxWidth: 240 }}
                      required
                      value={linkStudentId}
                      onChange={(e) => setLinkStudentId(e.target.value)}
                    >
                      <option value="">— select roster entry —</option>
                      {data.unlinked.map((s) => (
                        <option key={s.id} value={s.id}>
                          {personName(`${s.first_name} ${s.last_name}`)} (
                          {s.student_type.charAt(0).toUpperCase() + s.student_type.slice(1)})
                        </option>
                      ))}
                    </select>
                    <button type="submit" className="btn btn-sm btn-outline-primary">Compare</button>
                  </form>
                </>
              ) : (
                <p className="text-muted mb-0">No unlinked roster entries available.</p>
              )}
            </div>
          </div>
        </div>

        {/* Password Reset */}
        <div className="col-12">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Password</span>
              <button className="btn btn-sm btn-success" type="button" onClick={() => setPwOpen((v) => !v)}>
                Change
              </button>
            </div>
            <div className={`collapse${pwOpen ? ' show' : ''}`} id="pwResetBox">
              <div className="card-body border-top">
                <form
                  className="d-flex gap-2 align-items-center flex-wrap"
                  onSubmit={(e) => {
                    e.preventDefault();
                    void run({ action: 'reset_password', new_password: newPassword }, 'Password updated.').then(
                      (ok) => {
                        if (ok) {
                          setNewPassword('');
                          setPwOpen(false);
                        }
                      },
                    );
                  }}
                >
                  <input
                    type="text"
                    name="new_password"
                    className="form-control form-control-sm"
                    placeholder="New password (min 8 chars)"
                    style={{ maxWidth: 240 }}
                    value={newPassword}
                    onChange={(e) => setNewPassword(e.target.value)}
                  />
                  <button type="submit" className="btn btn-sm btn-warning">Set Password</button>
                  <button type="button" className="btn btn-sm btn-secondary" onClick={() => setPwOpen(false)}>
                    Cancel
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>

        {/* Activate / Deactivate */}
        {!isSelf && (
          <div className="col-12">
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold">Account Status</div>
              <div className="card-body d-flex align-items-center justify-content-between gap-3">
                <div>
                  {u.active ? (
                    <>
                      <span className="badge bg-secondary me-2">Activated</span> This account can log in.
                    </>
                  ) : (
                    <>
                      <span className="badge bg-danger me-2">Deactivated</span> This account cannot log in.
                    </>
                  )}
                </div>
                <button
                  className={`btn btn-sm ${u.active ? 'btn-outline-danger' : 'btn-outline-success'}`}
                  onClick={() => {
                    if (window.confirm(`${u.active ? 'Deactivate' : 'Activate'} this account?`)) {
                      void run({ action: 'toggle_active' }, 'Changes saved.');
                    }
                  }}
                >
                  {u.active ? 'Deactivate' : 'Activate'}
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Delete Account */}
        {!isSelf && (
          <div className="col-12">
            <button className="btn btn-outline-danger" onClick={() => void deleteUser()}>
              Delete Login Account
            </button>
          </div>
        )}
      </div>
    </>
  );
}
