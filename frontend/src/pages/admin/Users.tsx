// User Accounts — React port of admin/users.php: linked/unlinked account
// tables with username search and role/status filters. Read-only here —
// the actions (deactivate, reset password, link/unlink) live on the
// user_profile.php detail page each row's View button opens.

import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiGet, ApiError } from '../../api/client';
import type { AdminUser, AdminUsersData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, personName } from '../../format';

const ROLE_TIPS: Record<string, string> = {
  admin: 'Full administrative access to all portal features',
  instructor: 'Can take attendance and view the student roster',
  student: 'Paying participant — $30/month tuition',
  guest: 'Non-paying participant — registration fee not yet paid',
  parent: "Family account — manages linked children's profiles and payments",
};

const ROLE_BADGES: Record<string, string> = {
  admin: 'bg-danger',
  instructor: 'bg-warning text-dark',
  student: 'bg-primary',
  parent: 'bg-info text-dark',
  guest: 'bg-secondary',
};

interface Filters {
  q: string;
  role: string;
  status: string;
}

function rowMatches(u: AdminUser, f: Filters): boolean {
  if (f.q && !u.username.toLowerCase().includes(f.q.toLowerCase().trim())) return false;
  if (f.role && u.role !== f.role) return false;
  if (f.status && (u.active ? 'active' : 'inactive') !== f.status) return false;
  return true;
}

function UserRow({
  u,
  currentUserId,
  showRoster,
  filters,
}: {
  u: AdminUser;
  currentUserId: number;
  showRoster: boolean;
  filters: Filters;
}) {
  const cls = ROLE_BADGES[u.role] ?? 'bg-secondary';
  const tip = ROLE_TIPS[u.role] ?? '';
  return (
    <tr
      className={!u.active ? 'text-muted' : ''}
      data-name={u.username.toLowerCase()}
      data-role={u.role}
      data-status={u.active ? 'active' : 'inactive'}
      style={{ display: rowMatches(u, filters) ? '' : 'none' }}
    >
      <td className="fw-semibold">{u.username}</td>
      {showRoster && (
        <td>
          {u.student_id !== null && (
            <a
              href={`#/instructor/student/${u.student_id}`}
              className="text-decoration-none"
            >
              {personName(u.student_name ?? '')}
            </a>
          )}
        </td>
      )}
      <td>
        <span className={`badge ${cls}`} title={tip}>
          {u.role.charAt(0).toUpperCase() + u.role.slice(1)}
        </span>
        {u.id === currentUserId && <> <span className="badge bg-secondary">you</span></>}
      </td>
      <td>
        {u.active ? (
          <span className="badge bg-secondary" title="Activated: this login is enabled and can sign in">
            Activated
          </span>
        ) : (
          <span className="badge bg-danger" title="Deactivated: this login has been disabled — cannot sign in">
            Deactivated
          </span>
        )}
      </td>
      <td>{u.last_login ? fmtDate(u.last_login) : 'Never'}</td>
      <td>
        <a href={`user_profile.php?id=${u.id}`} className="btn btn-sm btn-outline-secondary">View</a>
      </td>
    </tr>
  );
}

export default function Users() {
  const [searchParams] = useSearchParams();
  const [data, setData] = useState<AdminUsersData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [filters, setFilters] = useState<Filters>({ q: '', role: '', status: '' });
  const flash = searchParams.get('msg') === 'deleted' ? 'User account deleted.' : '';

  useEffect(() => {
    apiGet<AdminUsersData>('/admin/users.php')
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load user accounts.'));
  }, []);

  if (!data || error) return <PageState error={error} loading />;

  const linked = data.users.filter((u) => u.student_id !== null);
  const unlinked = data.users.filter((u) => u.student_id === null);
  const set = (key: keyof Filters) => (value: string) => setFilters((f) => ({ ...f, [key]: value }));

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h3 className="mb-0">User Accounts</h3>
      </div>
      {flash && <div className="alert alert-success">{flash}</div>}
      <div className="d-flex gap-2 align-items-center mb-4 flex-wrap">
        <input
          type="text"
          id="userSearch"
          className="form-control form-control-sm"
          placeholder="Search username…"
          style={{ width: 180 }}
          value={filters.q}
          onChange={(e) => set('q')(e.target.value)}
        />
        <select id="filterRole" className="form-select form-select-sm" style={{ width: 130 }}
                value={filters.role} onChange={(e) => set('role')(e.target.value)}>
          <option value="">All Roles</option>
          <option value="student">Student</option>
          <option value="instructor">Instructor</option>
          <option value="admin">Admin</option>
          <option value="guest">Guest</option>
          <option value="parent">Parent</option>
        </select>
        <select id="filterStatus" className="form-select form-select-sm" style={{ width: 130 }}
                value={filters.status} onChange={(e) => set('status')(e.target.value)}>
          <option value="">All Statuses</option>
          <option value="active">Activated</option>
          <option value="inactive">Deactivated</option>
        </select>
      </div>

      <div className="card border-0 shadow-sm mb-4">
        <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>Linked Accounts</span>
          <span className="badge bg-primary">{linked.filter((u) => rowMatches(u, filters)).length}</span>
        </div>
        <div className="card-body p-0">
          <div className="table-responsive">
            <table className="table table-hover mb-0 align-middle" id="linkedTable">
              <thead className="table-light">
                <tr>
                  <th>Username</th>
                  <th>Roster Entry</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Last Login</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {linked.map((u) => (
                  <UserRow key={u.id} u={u} currentUserId={data.current_user_id} showRoster filters={filters} />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div className="card border-0 shadow-sm mb-4">
        <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>Unlinked Accounts</span>
          <span className="badge bg-primary">{unlinked.filter((u) => rowMatches(u, filters)).length}</span>
        </div>
        <div className="card-body p-0">
          <div className="table-responsive">
            <table className="table table-hover mb-0 align-middle" id="unlinkedTable">
              <thead className="table-light">
                <tr>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Status</th>
                  <th>Last Login</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {unlinked.map((u) => (
                  <UserRow key={u.id} u={u} currentUserId={data.current_user_id} showRoster={false} filters={filters} />
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </>
  );
}
