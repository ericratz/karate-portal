// Admin Dashboard — React port of admin/index.php: stat cards, the 12-month
// revenue/expenses chart (hidden on phones like the PHP page), attendance and
// rent warning banners, the tuition-unpaid / missing-waiver lists, the
// registration/linking alert queues, and recent payments. Action links point
// at the legacy .php URLs (all redirect stubs or still-PHP pages), keeping
// every href the specs and muscle memory rely on.

import { useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { AdminAlertRow, AdminDashboardData } from '../../api/types';
import RevenueChart from '../../components/RevenueChart';
import { PageState, TruncatedText } from '../../components/shared';
import { fmtDate, paymentType, personName } from '../../format';

const LR_LABELS: Record<string, [string, string]> = {
  new_guest: ['New Student', 'bg-success'],
  existing_student: ['Existing Student', 'bg-primary'],
  parent: ['Parent', 'bg-info text-dark'],
};

const ORANGE_BADGE = { backgroundColor: '#fd7e14', color: '#fff' };

function CountBadge({ n }: { n: number }) {
  return <span className="badge" style={ORANGE_BADGE}>{n}</span>;
}

function WarningBanner({ children }: { children: React.ReactNode }) {
  const [closed, setClosed] = useState(false);
  if (closed) return null;
  return (
    <div className="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
      {children}
      <button type="button" className="btn-close ms-auto" aria-label="Close" onClick={() => setClosed(true)} />
    </div>
  );
}

export default function AdminDashboard() {
  const [searchParams] = useSearchParams();
  const [data, setData] = useState<AdminDashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState(() => (searchParams.get('linked') ? 'Accounts linked successfully.' : ''));

  const load = useCallback(() => {
    apiGet<AdminDashboardData>('/admin/dashboard.php')
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the dashboard.'));
  }, []);

  useEffect(load, [load]);

  const dismissAlert = async (lrId: number, confirmText?: string) => {
    if (confirmText && !window.confirm(confirmText)) return;
    try {
      await apiPost('/admin/dashboard.php', { action: 'dismiss_alert', lr_id: lrId });
      load();
    } catch (e: unknown) {
      setMsg('');
      setError(e instanceof ApiError ? e.message : 'Could not dismiss the alert.');
    }
  };

  if (!data || error) return <PageState error={error} loading />;

  const now = new Date();
  const monthName = now.toLocaleString('en-US', { month: 'long' });
  const year = now.getFullYear();

  const stats: [string, string, string][] = [
    ['Active Students', String(data.stats.active_students), 'text-primary'],
    ['Total Students', String(data.stats.active_students + data.stats.inactive_students), 'text-primary'],
    [`Revenue (${monthName})`, `$${data.stats.revenue_month.toFixed(2)}`, 'text-success'],
    [`Revenue (${year})`, `$${data.stats.revenue_ytd.toFixed(2)}`, 'text-success'],
    [`Paid to Center Stage (${year})`, `$${data.stats.rent_ytd.toFixed(2)}`, 'text-danger'],
  ];

  const alertTable = (
    rows: AdminAlertRow[],
    variant: 'linking' | 'claimed' | 'new',
  ) => (
    <div className="table-responsive">
      <table className="table table-sm table-hover mb-0">
        <thead className="table-light">
          <tr>
            <th>{variant === 'claimed' ? 'Login' : 'User'}</th>
            {variant === 'claimed' && <th>Linked To</th>}
            <th>Date</th>
            <th className="col-action" />
          </tr>
        </thead>
        <tbody>
          {rows.map((a) => (
            <tr key={a.id}>
              <td>
                <div className="fw-semibold small">{a.username}</div>
                <div className="text-muted small">{a.user_name}</div>
              </td>
              {variant === 'claimed' && (
                <td className="small">
                  {a.student_id !== null && a.student_name ? (
                    <a href={`#/instructor/student/${a.student_id}`}>{a.student_name}</a>
                  ) : (
                    '—'
                  )}
                </td>
              )}
              <td className="small text-muted text-nowrap">{fmtDate(a.created_at)}</td>
              <td className="text-nowrap">
                {variant === 'linking' && (
                  <a href={`resolve_link.php?lr_id=${a.id}`} className="btn btn-sm btn-warning py-0">Resolve</a>
                )}{' '}
                <button
                  className="btn btn-sm btn-outline-secondary py-0"
                  onClick={() =>
                    void dismissAlert(
                      a.id,
                      variant === 'linking'
                        ? 'Dismiss this alert? Use this only if the account is already correctly linked (e.g. a parent-only account with no separate roster record).'
                        : undefined,
                    )
                  }
                >
                  Dismiss
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );

  return (
    <>
      {msg && <div className="alert alert-success">{msg}</div>}

      {data.attendance_alert.show && (
        <WarningBanner>
          <span className="fw-semibold">⚠ Attendance not recorded</span> — No class session found for{' '}
          {fmtDate(data.attendance_alert.date)}.
          <a href={`#/instructor/attendance?date=${data.attendance_alert.date}`} className="alert-link ms-1">
            Record now →
          </a>
        </WarningBanner>
      )}

      {data.rent_alert && (
        <WarningBanner>
          <span className="fw-semibold">⚠ Rent not recorded</span> — No rent payment found for {monthName} {year}.
          <a href="expenses.php" className="alert-link ms-1">Record now →</a>
        </WarningBanner>
      )}

      {/* Stat cards */}
      <div className="row g-3 mb-4">
        {stats.map(([label, val, cls]) => (
          <div className="col-6 col-lg" key={label}>
            <div className="card border-0 shadow-sm text-center h-100">
              <div className="card-body">
                <div className={`display-6 fw-bold ${cls}`}>{val}</div>
                <div className="text-muted small">{label}</div>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* Revenue chart (hidden on phones — too cramped to read) */}
      <div className="card border-0 shadow-sm mb-4 d-none d-md-block">
        <div className="card-header bg-white fw-semibold">Revenue and Expenses</div>
        <div className="card-body">
          <RevenueChart labels={data.chart.labels} data={data.chart.data} />
        </div>
      </div>

      <div className="row g-4">
        {/* Attention items */}
        <div className="col-lg-5 d-flex flex-column gap-4">
          {/* Unpaid tuition this month */}
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between">
              <span>Tuition Unpaid — {monthName} {year}</span>
              <CountBadge n={data.unpaid.length} />
            </div>
            <div className="card-body p-0" style={{ maxHeight: 220, overflowY: 'auto' }}>
              {data.unpaid.length === 0 ? (
                <p className="p-3 text-success mb-0">All students paid ✓</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <tbody>
                      {data.unpaid.map((s) => (
                        <tr key={s.id}>
                          <td>
                            <a href={`#/instructor/student/${s.id}`}>{personName(s.name)}</a>
                          </td>
                          <td>
                            <a
                              href={`payments.php?action=add&student_id=${s.id}&type=monthly_tuition`}
                              className="btn btn-success btn-sm py-0"
                            >
                              Record Payment
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

          {/* Missing waivers */}
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between">
              <span>Missing Waivers</span>
              <CountBadge n={data.no_waiver.length} />
            </div>
            <div className="card-body p-0">
              {data.no_waiver.length === 0 ? (
                <p className="p-3 text-success mb-0">All waivers signed ✓</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <tbody>
                      {data.no_waiver.map((s) => (
                        <tr key={s.id}>
                          <td>
                            <a href={`#/admin/student-edit?id=${s.id}`}>{personName(s.name)}</a>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>

          {/* Needs Manual Linking (action required) */}
          {data.alerts_linking.length > 0 && (
            <div className="card border-0 shadow-sm border-start border-4 border-danger">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Needs Manual Linking</span>
                <CountBadge n={data.alerts_linking.length} />
              </div>
              <div className="card-body p-0">{alertTable(data.alerts_linking, 'linking')}</div>
            </div>
          )}

          {/* Claimed Existing Records (auto-linked, FYI) */}
          {data.alerts_claimed.length > 0 && (
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Claimed Existing Records</span>
                <CountBadge n={data.alerts_claimed.length} />
              </div>
              <div className="card-body p-0">{alertTable(data.alerts_claimed, 'claimed')}</div>
            </div>
          )}

          {/* New Registrations (FYI) */}
          {data.alerts_new.length > 0 && (
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>New Registrations</span>
                <CountBadge n={data.alerts_new.length} />
              </div>
              <div className="card-body p-0">{alertTable(data.alerts_new, 'new')}</div>
            </div>
          )}

          {/* Legacy link requests (old notify flow) */}
          {data.link_requests.length > 0 && (
            <div className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Link Requests</span>
                <CountBadge n={data.link_requests.length} />
              </div>
              <div className="card-body p-0">
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr><th>User</th><th>Type</th><th>Notes</th><th>Date</th><th className="col-action" /></tr>
                    </thead>
                    <tbody>
                      {data.link_requests.map((lr) => {
                        const [lbl, cls] = LR_LABELS[lr.request_type] ?? [
                          lr.request_type.charAt(0).toUpperCase() + lr.request_type.slice(1),
                          'bg-secondary',
                        ];
                        return (
                          <tr key={lr.id}>
                            <td>
                              <div className="fw-semibold small">{lr.username}</div>
                              <div className="text-muted small">{lr.user_name}</div>
                            </td>
                            <td><span className={`badge ${cls}`}>{lbl}</span></td>
                            <td className="small text-muted" style={{ maxWidth: 160 }}><TruncatedText text={lr.notes} empty="—" /></td>
                            <td className="small text-muted text-nowrap">{fmtDate(lr.created_at)}</td>
                            <td>
                              <a
                                href={`compare_account.php?user_id=${lr.user_id}&link_request_id=${lr.id}`}
                                className="btn btn-sm btn-outline-primary py-0"
                              >
                                Review
                              </a>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          )}

          {/* Possible account links */}
          {data.possible_links.length > 0 && (
            <div className="card border-0 shadow-sm border-start border-4 border-info">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between">
                <span>Possible Account Links</span>
                <CountBadge n={data.possible_links.length} />
              </div>
              <div className="card-body p-0">
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr><th>Login</th><th>Matches Roster</th><th className="col-action" /></tr>
                    </thead>
                    <tbody>
                      {data.possible_links.map((m) => (
                        <tr key={`${m.user_id}-${m.student_id}`}>
                          <td>
                            <div>{m.username}</div>
                            <small className="text-muted">{m.user_name}</small>
                          </td>
                          <td>
                            <a href={`#/instructor/student/${m.student_id}`}>{m.student_name}</a>
                            <br />
                            <small className="text-muted">{m.email_match ? 'email match' : 'name match'}</small>
                          </td>
                          <td>
                            <a
                              href={`compare_account.php?user_id=${m.user_id}&student_id=${m.student_id}`}
                              className="btn btn-sm btn-primary py-0"
                            >
                              Compare
                            </a>
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

        {/* Recent payments */}
        <div className="col-lg-7">
          <div className="card border-0 shadow-sm h-100">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between">
              <span>Recent Payments</span>
              {data.has_more_payments && (
                <a href="payments.php" className="btn btn-sm btn-outline-primary">View All</a>
              )}
            </div>
            <div className="card-body p-0">
              {data.recent_payments.length === 0 ? (
                <p className="p-3 text-muted">No payments yet.</p>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr>
                        <th>Date</th>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.recent_payments.map((p, i) => (
                        <tr key={i}>
                          <td>{fmtDate(p.payment_date)}</td>
                          <td>{personName(p.name)}</td>
                          <td>{paymentType(p.payment_type)}</td>
                          <td>${p.amount.toFixed(2)}</td>
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
