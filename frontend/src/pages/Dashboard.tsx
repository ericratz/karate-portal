// The tabbed family dashboard — React port of parent/index.php: family tabs,
// summary stat cards, 12-month attendance chart, profile card with inline
// edit, recent activity tables, rank history, and the child summary table.

import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { apiGet, ApiError } from '../api/client';
import type { StudentDashboard, StudentProfile } from '../api/types';
import { hwIndexUrl, TEST_INFO_URL } from '../belt';
import AttendanceChart from '../components/AttendanceChart';
import ProfileCard from '../components/ProfileCard';
import { ExtIcon, FamilyTabs, PageState, ScoreBadge } from '../components/shared';
import { fmtDate, fmtMonth, money, paymentType, personName } from '../format';
import { useSession } from '../SessionContext';

function StatCards({ data, studentId }: { data: StudentDashboard; studentId: number }) {
  const { rank, next_rank, att_summary, has_autopay, student } = data;
  return (
    <div className="row g-3 mb-4">
      <div className="col-sm-6 col-lg-3">
        <div className="card text-center h-100 border-0 shadow-sm">
          <div className="card-body d-flex flex-column align-items-center justify-content-center gap-1">
            <div className="fs-3 fw-bold text-primary">{att_summary.attended}</div>
            <div className="text-muted small">Classes Attended</div>
          </div>
        </div>
      </div>

      <div className="col-sm-6 col-lg-3">
        <div className="card text-center h-100 border-0 shadow-sm">
          <div className="card-body d-flex flex-column align-items-center justify-content-center gap-1">
            {rank ? (
              <a
                href={`../admin/certificate.php?student_id=${studentId}&rank_id=${rank.rank_id}`}
                target="_blank"
                rel="noreferrer"
                className="fs-3 fw-bold text-decoration-none"
                style={{ color: '#6f42c1' }}
              >
                {rank.name}<ExtIcon />
              </a>
            ) : (
              <div className="fs-3 fw-bold" style={{ color: '#6f42c1' }}>—</div>
            )}
            <div className="text-muted small">Current Rank</div>
          </div>
        </div>
      </div>

      {next_rank?.hw_url && (
        <div className="col-sm-6 col-lg-3">
          <div className="card text-center h-100 border-0 shadow-sm">
            <div className="card-body d-flex flex-column align-items-center justify-content-center gap-1">
              <a href={next_rank.hw_url} target="_blank" rel="noreferrer" className="fs-3 fw-bold text-decoration-none">
                {next_rank.name}<ExtIcon />
              </a>
              <div className="text-muted small">Next Belt Homework</div>
            </div>
          </div>
        </div>
      )}

      {next_rank?.test_url && (
        <div className="col-sm-6 col-lg-3">
          <div className="card text-center h-100 border-0 shadow-sm">
            <div className="card-body d-flex flex-column align-items-center justify-content-center gap-1">
              <a href={next_rank.test_url} target="_blank" rel="noreferrer" className="fs-3 fw-bold text-decoration-none">
                {next_rank.name}<ExtIcon />
              </a>
              <div className="text-muted small">Next Belt Test</div>
            </div>
          </div>
        </div>
      )}

      {!student.injury_waiver && (
        <div className="col-sm-6 col-lg-3">
          <div className="card text-center h-100 border-0 shadow-sm">
            <div className="card-body d-flex flex-column align-items-center justify-content-center gap-1">
              <Link
                to={`/waiver/${studentId}`}
                className="fs-3 fw-bold text-danger text-decoration-none"
                aria-label="Liability waiver not signed — click to sign"
              >
                ✗
              </Link>
              <div className="text-muted small">Complete Waiver</div>
            </div>
          </div>
        </div>
      )}

      {has_autopay && (
        <div className="col-sm-6 col-lg-3">
          <div className="card text-center h-100 border-0 shadow-sm">
            <div className="card-body d-flex flex-column align-items-center justify-content-center gap-1">
              <div className="fs-3 fw-bold text-success">✓</div>
              <div className="text-muted small">Auto-Pay Active</div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function ChildSummary() {
  const { family } = useSession();
  if (family.children.length === 0) return null;
  return (
    <div className="card border-0 shadow-sm">
      <div className="card-header bg-white fw-semibold">Child Summary</div>
      <div className="card-body p-0">
        <div className="table-responsive">
          <table className="table table-sm table-hover mb-0">
            <thead className="table-light">
              <tr>
                <th>Name</th>
                <th>Last Attendance</th>
                <th>Last Payment</th>
                <th>Waiver</th>
                <th>Next Test HW</th>
              </tr>
            </thead>
            <tbody>
              {family.children.map((ch) => (
                <tr key={ch.id}>
                  <td>
                    <Link to={`/student/${ch.id}`}>{personName(`${ch.first_name} ${ch.last_name}`)}</Link>
                  </td>
                  <td>{ch.last_attendance ? fmtDate(ch.last_attendance) : ''}</td>
                  <td>
                    {ch.last_payment && (
                      <>
                        {fmtDate(ch.last_payment.date)}
                        <span className="text-muted small ms-1">{paymentType(ch.last_payment.type)}</span>
                      </>
                    )}
                  </td>
                  <td>
                    {ch.injury_waiver
                      ? <span className="text-success">✓</span>
                      : <span className="text-danger">✗</span>}
                  </td>
                  <td>
                    {ch.next_rank?.hw_url && (
                      <a href={ch.next_rank.hw_url} target="_blank" rel="noreferrer" className="text-decoration-none small">
                        {ch.next_rank.name} ↗
                      </a>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

export default function Dashboard() {
  const { family } = useSession();
  const { id } = useParams();

  const defaultId = family.own_student?.id ?? family.children[0]?.id ?? 0;
  const requestedId = id ? Number(id) : defaultId;
  const validIds = [
    ...(family.own_student ? [family.own_student.id] : []),
    ...family.children.map((c) => c.id),
  ];
  const studentId = validIds.includes(requestedId) ? requestedId : defaultId;

  const [data, setData] = useState<StudentDashboard | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!studentId) return;
    setData(null);
    setError(null);
    apiGet<StudentDashboard>(`/parent/student.php?student_id=${studentId}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load dashboard.'));
  }, [studentId]);

  if (!studentId) {
    return (
      <div className="alert alert-info">
        No student profile linked to this account yet. Please contact Noji.
      </div>
    );
  }

  const isOwnTab = family.own_student !== null && studentId === family.own_student.id;

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
          {data ? (
            <>
              <h3 className="mb-0">Welcome, {personName(data.student.first_name)}!</h3>
              <small className="text-muted">
                Member since {data.student.registration_date ? fmtDate(data.student.registration_date) : '—'}
              </small>
            </>
          ) : (
            <h3 className="mb-0">My Dashboard</h3>
          )}
        </div>
        <div className="d-flex gap-2 flex-wrap">
          <a
            href={hwIndexUrl(data?.student.date_of_birth ?? null)}
            target="_blank"
            rel="noreferrer"
            className="btn text-white"
            style={{ backgroundColor: '#0052cc', borderColor: '#0052cc' }}
          >
            All Homework <ExtIcon size={12} />
          </a>
          <a
            href={TEST_INFO_URL}
            target="_blank"
            rel="noreferrer"
            className="btn text-white"
            style={{ backgroundColor: '#0052cc', borderColor: '#0052cc' }}
          >
            Test Info <ExtIcon size={12} />
          </a>
          <a
            href={`../admin/member_card.php?student_id=${studentId}`}
            target="_blank"
            rel="noreferrer"
            className="btn text-white"
            style={{ backgroundColor: '#0052cc', borderColor: '#0052cc' }}
          >
            Member Card <ExtIcon size={12} />
          </a>
          <Link to={`/pay/${studentId}`} className="btn btn-success">
            Make a Payment
          </Link>
        </div>
      </div>

      <FamilyTabs activeId={studentId} />

      {error && <div className="alert alert-danger">{error}</div>}
      {!data && !error && <PageState error={null} loading />}

      {data && (
        <>
          <StatCards data={data} studentId={studentId} />

          <div className="card border-0 shadow-sm mb-4 d-none d-md-block">
            <div className="card-header bg-white fw-semibold border-bottom">
              Attendance — Last 12 Months
            </div>
            <div className="card-body" style={{ height: 220 }}>
              <AttendanceChart months={data.attendance_chart} />
            </div>
          </div>

          <div className="row g-4">
            <div className="col-md-6 d-flex flex-column gap-4">
              <ProfileCard
                student={data.student}
                onSaved={(s: StudentProfile) => setData({ ...data, student: s })}
              />

              <div className="card border-0 shadow-sm">
                <div className="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                  <span>Recent Attendance</span>
                  {data.recent_attendance.length === 10 && (
                    <Link to={`/attendance/${studentId}`} className="btn btn-sm btn-outline-secondary">Show All</Link>
                  )}
                </div>
                <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
                  {data.recent_attendance.length === 0 ? (
                    <p className="p-3 text-muted">No attendance recorded yet.</p>
                  ) : (
                    <div className="table-responsive">
                      <table className="table table-sm table-hover mb-0">
                        <thead className="table-light"><tr><th>Date Attended</th><th>Type</th></tr></thead>
                        <tbody>
                          {data.recent_attendance.map((row, i) => (
                            <tr key={`${row.session_date}-${i}`}>
                              <td>{fmtDate(row.session_date)}</td>
                              <td>{row.class_type.charAt(0).toUpperCase() + row.class_type.slice(1)}</td>
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
                <div className="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                  <span>Recent Payments</span>
                  {data.recent_payments.length === 10 && (
                    <Link to={`/payments/${studentId}`} className="btn btn-sm btn-outline-secondary">Show All</Link>
                  )}
                </div>
                <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
                  {data.recent_payments.length === 0 ? (
                    <p className="p-3 text-muted">No payments on record.</p>
                  ) : (
                    <div className="table-responsive">
                      <table className="table table-sm table-hover mb-0">
                        <thead className="table-light">
                          <tr><th>Date</th><th>Type</th><th className="text-end">Amount</th></tr>
                        </thead>
                        <tbody>
                          {data.recent_payments.map((p, i) => (
                            <tr key={`${p.payment_date}-${i}`}>
                              <td>{fmtDate(p.payment_date)}</td>
                              <td>{paymentType(p.payment_type)}</td>
                              <td className="text-end">{money(Number(p.amount))}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              </div>

              <div className="card border-0 shadow-sm">
                <div className="card-header bg-white fw-semibold border-bottom d-flex justify-content-between align-items-center">
                  <span>Belt Tests</span>
                  {data.recent_belt_tests.length === 10 && (
                    <Link to={`/belt-tests/${studentId}`} className="btn btn-sm btn-outline-secondary">Show All</Link>
                  )}
                </div>
                <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
                  {data.recent_belt_tests.length === 0 ? (
                    <p className="p-3 text-muted">No belt tests on record.</p>
                  ) : (
                    <div className="table-responsive">
                      <table className="table table-sm table-hover mb-0">
                        <thead className="table-light">
                          <tr><th>Date</th><th>Testing For</th><th>Score</th><th>Fee</th><th>Awarded</th></tr>
                        </thead>
                        <tbody>
                          {data.recent_belt_tests.map((t, i) => (
                            <tr key={`${t.test_date}-${i}`}>
                              <td>{fmtDate(t.test_date)}</td>
                              <td>{t.kyu_dan}</td>
                              <td><ScoreBadge result={t.result} score={t.score === null ? null : Number(t.score)} /></td>
                              <td>
                                {Number(t.fee_paid)
                                  ? <span className="text-success">✓</span>
                                  : <span className="text-danger">✗</span>}
                              </td>
                              <td>
                                {t.result === 'pass' ? (
                                  <span className="text-success">✓</span>
                                ) : t.result === 'fail' ? (
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

              <div className="card border-0 shadow-sm">
                <div className="card-header bg-white fw-semibold">Rank History</div>
                <div className="card-body p-0" style={{ maxHeight: 300, overflowY: 'auto' }}>
                  {data.rank_history.length === 0 ? (
                    <p className="p-3 text-muted">No ranks recorded.</p>
                  ) : (
                    <div className="table-responsive">
                      <table className="table table-sm mb-0">
                        <thead className="table-light">
                          <tr><th>Rank</th><th>Date Achieved</th><th /></tr>
                        </thead>
                        <tbody>
                          {data.rank_history.map((rh, i) => (
                            <tr key={`${rh.kyu_dan}-${i}`} className={i === 0 ? 'table-purple' : ''}>
                              <td>{rh.kyu_dan}</td>
                              <td>{rh.achieved_date ? fmtMonth(rh.achieved_date) : '—'}</td>
                              <td>
                                <a
                                  href={`../admin/certificate.php?student_id=${studentId}&rank_id=${rh.rank_id}`}
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

              {isOwnTab && <ChildSummary />}
            </div>
          </div>
        </>
      )}
    </>
  );
}
