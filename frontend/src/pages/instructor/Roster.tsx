// Roster — React port of instructor/students.php: four type-grouped cards
// with live search + status/login/rank/waiver/attendance filters. Rows hide
// via inline display (never unmount) and the count badges track visible rows,
// exactly like the old page's filterRoster() — the specs assert both.

import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiGet, ApiError } from '../../api/client';
import type { RosterData, RosterStudent } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, personName } from '../../format';

type SortKey = 'first_name' | 'last_name';

interface Filters {
  q: string;
  status: string;
  login: string;
  rank: string;
  waiver: string;
  att: string;
}

const emptyFilters: Filters = { q: '', status: '', login: '', rank: '', waiver: '', att: '' };

const accountTooltips: Record<string, string> = {
  student: 'Student: paying participant ($30/month)',
  guest: 'Guest: non-paying participant (registration fee not yet paid)',
  parent: 'Parent: family account — one tuition payment covers the whole family',
  instructor: 'Instructor: teaches or assists with classes',
  admin: 'Admin: full administrative access',
};

function rowMatches(s: RosterStudent, f: Filters): boolean {
  if (f.q) {
    const hay = `${s.last_name} ${s.first_name} ${s.first_name} ${s.last_name}`.toLowerCase();
    if (!hay.includes(f.q.toLowerCase().trim())) return false;
  }
  if (f.status && (s.active ? 'active' : 'inactive') !== f.status) return false;
  if (f.login && (s.has_login ? 'yes' : 'no') !== f.login) return false;
  if (f.rank && (s.kyu_dan ?? '') !== f.rank) return false;
  if (f.waiver && (s.injury_waiver ? 'yes' : 'no') !== f.waiver) return false;
  if (f.att) {
    if (f.att === 'never') return !s.last_attended;
    if (!s.last_attended) return false;
    const d = new Date(s.last_attended);
    const now = new Date();
    if (f.att === 'year') return d.getFullYear() === now.getFullYear();
    const cutoff = new Date();
    cutoff.setDate(cutoff.getDate() - Number(f.att));
    return d >= cutoff;
  }
  return true;
}

function RosterTable({
  rows,
  sort,
  filters,
}: {
  rows: RosterStudent[];
  sort: SortKey;
  filters: Filters;
}) {
  return (
    <div className="table-responsive">
      <table
        className="table table-sm table-hover mb-0"
        style={{ width: '100%', minWidth: 560 }}
      >
        {/* No colgroup: the global .table table-layout:fixed rule splits the
            width equally across all five columns, so the gaps are uniform. */}
        <thead className="table-light">
          <tr>
            <th>Name</th><th>Rank</th><th>Waiver</th><th>Last Attended</th><th>Status</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((s) => {
            const displayName =
              sort === 'last_name'
                ? `${personName(s.last_name)}, ${personName(s.first_name)}`
                : personName(`${s.first_name} ${s.last_name}`);
            return (
              <tr
                key={s.id}
                data-name={`${s.last_name} ${s.first_name} ${s.first_name} ${s.last_name}`.toLowerCase()}
                style={{ display: rowMatches(s, filters) ? '' : 'none' }}
              >
                <td className="fw-semibold">
                  <Link to={`/instructor/student/${s.id}`} className="text-decoration-none">
                    {displayName}
                  </Link>
                  {s.medical_note && (
                    <span className="text-danger" style={{ fontSize: '.85em' }} title={s.medical_note}>
                      {' '}⚕
                    </span>
                  )}
                </td>
                <td className="text-muted small">{s.kyu_dan ?? '—'}</td>
                <td>
                  {s.injury_waiver
                    ? <span className="text-success">✓</span>
                    : <span className="text-danger">✗</span>}
                </td>
                <td>
                  <span className="text-muted small">
                    {s.last_attended ? fmtDate(s.last_attended) : 'Never'}
                  </span>
                </td>
                <td title={accountTooltips[s.student_type] ?? s.student_type}>
                  {s.active ? (
                    <span className="badge bg-success" title="Active: attended class in the last 3 months">
                      Active
                    </span>
                  ) : (
                    <span className="badge bg-secondary" title="Inactive: no attendance in the last 3 months">
                      Inactive
                    </span>
                  )}
                  {s.active_override && (
                    <>
                      {' '}
                      <span className="badge bg-warning text-dark" title="Override: active/inactive status manually set by admin">
                        Override
                      </span>
                    </>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export default function Roster() {
  const [searchParams] = useSearchParams();
  const sort: SortKey = searchParams.get('sort') === 'last_name' ? 'last_name' : 'first_name';

  const [data, setData] = useState<RosterData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [filters, setFilters] = useState<Filters>(emptyFilters);

  useEffect(() => {
    apiGet<RosterData>('/instructor/roster.php')
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the roster.'));
  }, []);

  const groups = useMemo(() => {
    if (!data) return null;
    const sorted = [...data.students].sort((a, b) => {
      const ka = sort === 'last_name' ? `${a.last_name} ${a.first_name}` : `${a.first_name} ${a.last_name}`;
      const kb = sort === 'last_name' ? `${b.last_name} ${b.first_name}` : `${b.first_name} ${b.last_name}`;
      return ka.localeCompare(kb, undefined, { sensitivity: 'base' });
    });
    return {
      instructors: sorted.filter((s) => s.student_type === 'instructor' || s.student_type === 'admin'),
      parents: sorted.filter((s) => s.student_type === 'parent'),
      students: sorted.filter((s) => s.student_type === 'student'),
      guests: sorted.filter((s) => s.student_type === 'guest'),
    };
  }, [data, sort]);

  if (!data || !groups || error) return <PageState error={error} loading />;

  const set = (key: keyof Filters) => (value: string) => setFilters((f) => ({ ...f, [key]: value }));

  const sections: [string, string, RosterStudent[]][] = [
    ['instructors', 'Instructors', groups.instructors],
    ['parents', 'Parents', groups.parents],
    ['students', 'Students', groups.students],
    ['guests', 'Guests', groups.guests],
  ];

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h3 className="mb-0">Roster</h3>
      </div>
      <div className="d-flex gap-2 align-items-center mb-4 flex-wrap">
        <span className="text-muted small">Sort:</span>
        <Link
          to="/instructor/roster?sort=first_name"
          className={`btn btn-sm btn-filter ${sort === 'first_name' ? 'active' : ''}`}
        >
          First Name
        </Link>
        <Link
          to="/instructor/roster?sort=last_name"
          className={`btn btn-sm btn-filter ${sort === 'last_name' ? 'active' : ''}`}
        >
          Last Name
        </Link>
        <span className="text-muted small ms-2">|</span>
        <input
          type="text"
          id="rosterSearch"
          className="form-control form-control-sm"
          placeholder="Search name…"
          style={{ width: 180 }}
          value={filters.q}
          onChange={(e) => set('q')(e.target.value)}
        />
        <select id="filterStatus" className="form-select form-select-sm" style={{ width: 130 }}
                value={filters.status} onChange={(e) => set('status')(e.target.value)}>
          <option value="">All Statuses</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
        <select id="filterLogin" className="form-select form-select-sm" style={{ width: 140 }}
                value={filters.login} onChange={(e) => set('login')(e.target.value)}>
          <option value="">All Accounts</option>
          <option value="yes">Has Login</option>
          <option value="no">No Login</option>
        </select>
        <select id="filterRank" className="form-select form-select-sm" style={{ width: 160 }}
                value={filters.rank} onChange={(e) => set('rank')(e.target.value)}>
          <option value="">All Ranks</option>
          {data.ranks.map((rk) => <option key={rk} value={rk}>{rk}</option>)}
        </select>
        <select id="filterWaiver" className="form-select form-select-sm" style={{ width: 150 }}
                value={filters.waiver} onChange={(e) => set('waiver')(e.target.value)}>
          <option value="">All Waivers</option>
          <option value="yes">Waiver Signed</option>
          <option value="no">No Waiver</option>
        </select>
        <select id="filterAttendance" className="form-select form-select-sm" style={{ width: 160 }}
                value={filters.att} onChange={(e) => set('att')(e.target.value)}>
          <option value="">Any Attendance</option>
          <option value="30">Last 30 Days</option>
          <option value="90">Last 90 Days</option>
          <option value="year">This Year</option>
          <option value="never">Never Attended</option>
        </select>
      </div>

      {sections.map(([key, label, rows]) => {
        if (rows.length === 0) return null;
        const visible = rows.filter((s) => rowMatches(s, filters)).length;
        return (
          <div
            key={key}
            className="card border-0 shadow-sm mb-4"
            id={`card-${key}`}
            style={{ display: visible === 0 ? 'none' : '' }}
          >
            <div className="card-header bg-white fw-semibold">
              {label} <span className="badge bg-primary ms-2" id={`count-${key}`}>{visible}</span>
            </div>
            <div className="card-body p-0">
              <RosterTable rows={rows} sort={sort} filters={filters} />
            </div>
          </div>
        );
      })}
    </>
  );
}
