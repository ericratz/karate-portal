// Instructor dashboard — React port of instructor/index.php: take-attendance
// date picker, recent classes, roster link, recent belt tests, plus the
// personal profile/pay buttons when the instructor has a linked student row.
// The "Record New Class" form navigates in-app to the attendance route on
// submit (a native action="attendance.php" resolves against the current shell,
// e.g. …/admin/, and 404s), so it works under any shell.

import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { apiGet, ApiError } from '../../api/client';
import type { InstructorDashboardData } from '../../api/types';
import { ExtIcon, PageState } from '../../components/shared';
import { fmtDate, personName } from '../../format';

/** "2099-01-15" → "Thu 15 Jan 2099" (PHP date('D d M Y')) */
function fmtDateShortDay(iso: string): string {
  const d = new Date(iso.slice(0, 10) + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return iso;
  return `${d.toLocaleString('en-US', { weekday: 'short' })} ${fmtDate(iso)}`;
}

function ResultBadge({ result }: { result: string }) {
  if (result === 'pass') return <span className="badge bg-success">Pass</span>;
  if (result === 'fail') return <span className="badge bg-danger">Fail</span>;
  return <span className="badge bg-secondary">Pending</span>;
}

export default function InstructorDashboard() {
  const [data, setData] = useState<InstructorDashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [newDate, setNewDate] = useState(() => new Date().toISOString().slice(0, 10));
  const navigate = useNavigate();

  useEffect(() => {
    apiGet<InstructorDashboardData>('/instructor/dashboard.php')
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the dashboard.'));
  }, []);

  if (!data || error) return <PageState error={error} loading />;

  return (
    <>
      {data.own_student_id > 0 && (
        <div className="d-flex justify-content-end gap-2 mb-3">
          <a
            href={`../admin/member_card.php?student_id=${data.own_student_id}`}
            target="_blank"
            rel="noreferrer"
            className="btn text-white"
            style={{ backgroundColor: '#0052cc', borderColor: '#0052cc' }}
          >
            Member Card <ExtIcon size={12} />
          </a>
          {data.has_children ? (
            <Link to="/" className="btn btn-outline-secondary">View Profile</Link>
          ) : (
            <Link to={`/instructor/student/${data.own_student_id}`} className="btn btn-outline-secondary">
              View Profile
            </Link>
          )}
          <Link
            to={data.has_children ? `/pay/${data.own_student_id}` : '/pay'}
            className="btn btn-success"
          >
            Make a Payment
          </Link>
        </div>
      )}

      <div className="row g-4">
        <div className="col-md-6 d-flex flex-column gap-4">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Take Attendance</div>
            <div className="card-body">
              {/* Navigate in-app to the attendance route; a native GET to
                  attendance.php resolves against the current shell (…/admin/)
                  and 404s. */}
              <form onSubmit={(e) => { e.preventDefault(); navigate(`/instructor/attendance?date=${newDate}`); }}>
                <div className="mb-3">
                  <label className="form-label" htmlFor="newClassDate">Class Date</label>
                  <input
                    type="date"
                    name="date"
                    id="newClassDate"
                    className="form-control"
                    required
                    value={newDate}
                    onChange={(e) => setNewDate(e.target.value)}
                  />
                </div>
                <button className="btn btn-primary w-100">Record New Class</button>
              </form>
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Recent Classes</span>
              <Link to="/instructor/classes" className="btn btn-sm btn-outline-secondary">
                View All Classes
              </Link>
            </div>
            <div className="card-body p-0">
              {data.recent_sessions.length === 0 ? (
                <p className="p-3 text-muted">No classes recorded yet.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr><th>Date</th><th>Type</th></tr>
                    </thead>
                    <tbody>
                      {data.recent_sessions.map((s) => (
                        <tr key={s.id}>
                          <td>
                            <Link to={`/instructor/attendance?date=${s.session_date}`} className="text-decoration-none">
                              {fmtDateShortDay(s.session_date)}
                            </Link>
                          </td>
                          <td>{s.class_type.charAt(0).toUpperCase() + s.class_type.slice(1)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </div>

        <div className="col-md-6 d-flex flex-column gap-4">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Students</div>
            <div className="card-body">
              <Link to="/instructor/roster" className="btn btn-primary w-100">View Student Roster</Link>
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Recent Belt Tests</span>
              <Link to="/instructor/belt-tests" className="btn btn-sm btn-outline-secondary">View Tests</Link>
            </div>
            <div className="card-body p-0">
              {data.recent_belt_tests.length === 0 ? (
                <p className="p-3 text-muted">No belt tests on record.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr><th>Date</th><th>Student</th><th>Testing For</th><th>Result</th></tr>
                    </thead>
                    <tbody>
                      {data.recent_belt_tests.map((t) => (
                        <tr key={t.id}>
                          <td className="text-nowrap">{fmtDate(t.test_date)}</td>
                          <td>
                            <Link to={`/instructor/student/${t.student_id}`} className="text-decoration-none">
                              {personName(t.student)}
                            </Link>
                          </td>
                          <td>{t.kyu_dan}</td>
                          <td><ResultBadge result={t.result} /></td>
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
    </>
  );
}
