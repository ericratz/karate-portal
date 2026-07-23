// Classes — React port of instructor/attendance_sessions.php: session list
// with type/year filters (pushed into the route query like the old
// hx-push-url) and single-open accordion detail rows (#det-N / #tog-N).
// Date links use in-app hash routes (#/instructor/attendance?date=…) so they
// work under any shell; a relative attendance.php would 404 under …/admin/.

import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiGet, ApiError } from '../../api/client';
import type { SessionsData } from '../../api/types';
import { ExtIcon, PageState } from '../../components/shared';
import { fmtDateWeekday, personName } from '../../format';
import { useSession } from '../../SessionContext';

const classTypeLabels: Record<string, string> = { class: 'Class', seminar: 'Seminar', private: 'Private' };

export default function Classes() {
  const { me } = useSession();
  const [searchParams, setSearchParams] = useSearchParams();
  const type = searchParams.get('type') ?? '';
  const year = searchParams.get('year') ?? '';
  const filtering = type !== '' || year !== '';

  const [data, setData] = useState<SessionsData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<number | null>(null);
  const [newDate, setNewDate] = useState(() => new Date().toISOString().slice(0, 10));

  useEffect(() => {
    setData(null);
    setOpen(null);
    const qs = new URLSearchParams();
    if (type) qs.set('type', type);
    if (year) qs.set('year', year);
    apiGet<SessionsData>(`/instructor/sessions.php?${qs.toString()}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load classes.'));
  }, [type, year]);

  function setFilter(key: 'type' | 'year', value: string) {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(key, value);
    else next.delete(key);
    setSearchParams(next);
  }

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <h4 className="mb-0">Classes</h4>
        <a
          href="../checkin.php"
          target="_blank"
          rel="noreferrer"
          className="btn btn-sm ms-2 text-white"
          style={{ backgroundColor: '#0052cc', borderColor: '#0052cc' }}
        >
          QR Check-in <ExtIcon size={12} />
        </a>
        {me.role === 'admin' && (
          <a href="../admin/checkin_pin.php" className="btn btn-sm btn-outline-secondary ms-1">
            Check-in PIN
          </a>
        )}
        <div className="d-flex flex-column align-items-stretch gap-1 ms-auto">
          <input
            type="date"
            id="newSessionDate"
            className="form-control form-control-sm"
            value={newDate}
            onChange={(e) => setNewDate(e.target.value)}
          />
          <Link id="newSessionBtn" to={`/instructor/attendance?date=${newDate}`} className="btn btn-success btn-sm">
            + Record New Class
          </Link>
        </div>
      </div>

      <div className="card border-0 shadow-sm mb-4">
        <div className="card-body py-3">
          <div className="row g-2 align-items-end">
            <div className="col-auto">
              <label className="form-label small mb-1" htmlFor="typeFilter">Type</label>
              <select
                name="type"
                id="typeFilter"
                className="form-select form-select-sm"
                value={type}
                onChange={(e) => setFilter('type', e.target.value)}
              >
                <option value="">All Types</option>
                <option value="class">Class</option>
                <option value="seminar">Seminar</option>
                <option value="private">Private</option>
              </select>
            </div>
            <div className="col-auto">
              <label className="form-label small mb-1" htmlFor="yearFilter">Year</label>
              <select
                name="year"
                id="yearFilter"
                className="form-select form-select-sm"
                value={year}
                onChange={(e) => setFilter('year', e.target.value)}
              >
                <option value="">All Years</option>
                {(data?.years ?? [new Date().getFullYear()]).map((y) => (
                  <option key={y} value={String(y)}>{y}</option>
                ))}
              </select>
            </div>
            {filtering && (
              <div className="col-auto">
                <Link to="/instructor/classes" className="btn btn-filter btn-sm">Clear</Link>
              </div>
            )}
          </div>
        </div>
      </div>

      {!data && <PageState error={error} loading />}

      {data && data.sessions.length === 0 && (
        <div className="alert alert-info">
          No classes found{filtering ? ' matching those filters' : ''}.
        </div>
      )}

      {data && data.sessions.length > 0 && (
        <div className="card border-0 shadow-sm">
          <div className="card-header bg-white fw-semibold">
            {data.sessions.length} class{data.sessions.length !== 1 ? 'es' : ''}
          </div>
          <div className="card-body p-0">
            <div className="table-responsive">
              <table className="table table-hover mb-0">
                <thead className="table-light">
                  <tr><th>Date</th><th>Type</th><th>Instructor</th><th>Present</th><th></th></tr>
                </thead>
                <tbody>
                  {data.sessions.map((sess, i) => (
                    <SessionRows
                      key={sess.id}
                      idx={i}
                      sess={sess}
                      open={open === i}
                      onToggle={() => setOpen(open === i ? null : i)}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </>
  );
}

function SessionRows({
  idx,
  sess,
  open,
  onToggle,
}: {
  idx: number;
  sess: SessionsData['sessions'][number];
  open: boolean;
  onToggle: () => void;
}) {
  return (
    <>
      <tr
        style={{ cursor: 'pointer' }}
        className="session-row"
        data-idx={idx}
        onClick={(e) => {
          if ((e.target as HTMLElement).closest('.session-link')) return;
          onToggle();
        }}
      >
        <td className="fw-medium">
          <Link to={`/instructor/attendance?date=${sess.session_date}`} className="text-decoration-none session-link">
            {fmtDateWeekday(sess.session_date)}
          </Link>
        </td>
        <td className="text-muted small">
          {classTypeLabels[sess.class_type] ?? sess.class_type}
        </td>
        <td className="small">
          {sess.instructors.length === 0
            ? <span className="text-muted">—</span>
            : sess.instructors.map((i) => personName(i.name)).join(', ')}
        </td>
        <td><span className="badge bg-primary">{sess.present_count}</span></td>
        <td className="text-muted" id={`tog-${idx}`}>{open ? '▲' : '▼'}</td>
      </tr>
      <tr id={`det-${idx}`} style={{ display: open ? '' : 'none' }}>
        <td colSpan={5} className="px-4 py-3">
          {sess.attendees.length === 0 ? (
            <span className="text-muted small">No attendance recorded.</span>
          ) : (
            <>
              <div className="small fw-semibold text-success mb-1">Present ({sess.attendees.length})</div>
              <div className="small">
                {sess.attendees
                  .map((a) => `${personName(a.first_name)} ${a.last_name.charAt(0).toUpperCase()}`)
                  .join(', ')}
              </div>
            </>
          )}
        </td>
      </tr>
    </>
  );
}
