// Logs — React port of admin/logs.php: Activity / Errors / Mail tabs with the
// shared timeframe filter (defaults to This Week) plus per-tab filters, all
// applied server-side exactly like the old page's WHERE clauses. The backup
// download stays a plain link to the PHP streaming endpoint.

import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiGet, ApiError } from '../../api/client';
import type { ActivityLogData, ErrorLogData, MailLogData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDateTime, paymentType } from '../../format';

// Rolling windows back from now (see api/v1/admin/logs.php), so the labels say
// so — "This Day" read as a calendar day and was misleading just after midnight.
const TIMEFRAMES: [string, string][] = [
  ['day', 'Last 24 Hours'],
  ['week', 'Last 7 Days'],
  ['month', 'Last 30 Days'],
  ['year', 'Last 365 Days'],
  ['all', 'All Time'],
];

const LEVEL_CLASSES: Record<string, string> = {
  debug: 'secondary',
  info: 'primary',
  warning: 'warning text-dark',
  error: 'danger',
  critical: 'danger',
};

function actionBadge(action: string): string {
  if (action.includes('delete')) return 'bg-danger';
  if (action.includes('fail')) return 'bg-warning text-dark';
  if (action.includes('login')) return 'bg-secondary';
  if (action.includes('award')) return 'bg-success';
  return 'bg-primary';
}

function CountHeader({ count, limit }: { count: number; limit: number }) {
  return (
    <div className="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
      <span>{count} entr{count !== 1 ? 'ies' : 'y'}</span>
      {count === limit && (
        <span className="text-warning small fw-normal">
          Result limit reached — use filters to narrow your search
        </span>
      )}
    </div>
  );
}

export default function Logs() {
  const [searchParams, setSearchParams] = useSearchParams();
  const tabParam = searchParams.get('tab') ?? 'activity';
  const tab = ['activity', 'error', 'mail'].includes(tabParam) ? tabParam : 'activity';
  const timeframe = searchParams.get('timeframe') ?? 'week';

  const [activity, setActivity] = useState<ActivityLogData | null>(null);
  const [errors, setErrors] = useState<ErrorLogData | null>(null);
  const [mail, setMail] = useState<MailLogData | null>(null);
  const [error, setError] = useState<string | null>(null);

  // Per-tab filters, from the query string like the old GET form
  const aAction = searchParams.get('action') ?? '';
  const aUser = searchParams.get('user') ?? '';
  const eLevel = searchParams.get('level') ?? '';
  const eChannel = searchParams.get('channel') ?? '';
  const mStatus = searchParams.get('status') ?? '';
  const mType = searchParams.get('type') ?? '';

  useEffect(() => {
    const qs = new URLSearchParams({ tab, timeframe });
    if (tab === 'activity') {
      if (aAction) qs.set('action', aAction);
      if (aUser) qs.set('user', aUser);
    } else if (tab === 'error') {
      if (eLevel) qs.set('level', eLevel);
      if (eChannel) qs.set('channel', eChannel);
    } else {
      if (mStatus) qs.set('status', mStatus);
      if (mType) qs.set('type', mType);
    }
    setActivity(null);
    setErrors(null);
    setMail(null);
    apiGet<ActivityLogData | ErrorLogData | MailLogData>(`/admin/logs.php?${qs.toString()}`)
      .then((data) => {
        if (tab === 'activity') setActivity(data as ActivityLogData);
        else if (tab === 'error') setErrors(data as ErrorLogData);
        else setMail(data as MailLogData);
      })
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the logs.'));
  }, [tab, timeframe, aAction, aUser, eLevel, eChannel, mStatus, mType]);

  // Functional updater so two rapid filter changes (e.g. timeframe then level)
  // don't race on a stale searchParams snapshot and drop each other's edit.
  const setFilter = (key: string, value: string) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (value) next.set(key, value);
      else next.delete(key);
      return next;
    });
  };

  const timeframeSelect = (
    <div className="col-md-3">
      <label className="form-label small mb-1">Timeframe</label>
      <select
        name="timeframe"
        className="form-select form-select-sm js-live-filter"
        value={timeframe}
        onChange={(e) => setFilter('timeframe', e.target.value)}
      >
        {TIMEFRAMES.map(([v, l]) => (
          <option key={v} value={v}>{l}</option>
        ))}
      </select>
    </div>
  );

  const loaded = tab === 'activity' ? activity !== null : tab === 'error' ? errors !== null : mail !== null;

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-3">
        <h4 className="mb-0">Logs</h4>
        <a href="db_backup.php" className="btn btn-blue btn-sm">⬇ Download Backup</a>
      </div>

      <ul className="nav nav-tabs mb-3">
        <li className="nav-item">
          <Link className={`nav-link ${tab === 'activity' ? 'active' : ''}`} to="/admin/logs?tab=activity">
            Activity
          </Link>
        </li>
        <li className="nav-item">
          <Link className={`nav-link ${tab === 'error' ? 'active' : ''}`} to="/admin/logs?tab=error">
            Errors
          </Link>
        </li>
        <li className="nav-item">
          <Link className={`nav-link ${tab === 'mail' ? 'active' : ''}`} to="/admin/logs?tab=mail">
            Mail
          </Link>
        </li>
      </ul>

      {!loaded || error ? (
        <PageState error={error} loading />
      ) : tab === 'activity' && activity ? (
        <>
          <div className="card border-0 shadow-sm mb-3">
            <div className="card-body py-2">
              <form className="row g-2 align-items-end" onSubmit={(e) => e.preventDefault()}>
                {timeframeSelect}
                <div className="col-md-3">
                  <label className="form-label small mb-1">Action</label>
                  <select name="action" className="form-select form-select-sm js-live-filter"
                          value={aAction} onChange={(e) => setFilter('action', e.target.value)}>
                    <option value="">All Actions</option>
                    {activity.actions.map((a) => <option key={a} value={a}>{a}</option>)}
                  </select>
                </div>
                <div className="col-md-3">
                  <label className="form-label small mb-1">User</label>
                  <select name="user" className="form-select form-select-sm js-live-filter"
                          value={aUser} onChange={(e) => setFilter('user', e.target.value)}>
                    <option value="">All Users</option>
                    {activity.users.map((u) => <option key={u} value={u}>{u}</option>)}
                  </select>
                </div>
              </form>
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <CountHeader count={activity.entries.length} limit={activity.limit} />
            <div className="card-body p-0">
              {activity.entries.length === 0 ? (
                <p className="p-3 text-muted">No entries match the filter.</p>
              ) : (
                <div style={{ overflowX: 'auto' }}>
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr>
                        <th>Date / Time</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Target</th>
                        <th>Detail</th>
                        <th>IP</th>
                      </tr>
                    </thead>
                    <tbody>
                      {activity.entries.map((e) => (
                        <tr key={e.id}>
                          <td className="text-nowrap small text-muted">{fmtDateTime(e.created_at)}</td>
                          <td className="small">{e.username ?? '—'}</td>
                          <td>
                            <span className={`badge ${actionBadge(e.action)}`}>{e.action}</span>
                          </td>
                          <td className="small text-muted">
                            {e.target_type && e.target_id !== null
                              ? `${e.target_type} #${e.target_id}`
                              : e.target_type ?? '—'}
                          </td>
                          <td className="small text-muted">{e.detail ?? ''}</td>
                          <td className="small text-muted">{e.ip_address ?? '—'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </>
      ) : tab === 'error' && errors ? (
        <>
          <div className="card border-0 shadow-sm mb-3">
            <div className="card-body py-2">
              <form className="row g-2 align-items-end" onSubmit={(e) => e.preventDefault()}>
                {timeframeSelect}
                <div className="col-md-3">
                  <label className="form-label small mb-1">Level</label>
                  <select name="level" className="form-select form-select-sm js-live-filter"
                          value={eLevel} onChange={(e) => setFilter('level', e.target.value)}>
                    <option value="">All Levels</option>
                    {errors.levels.map((l) => (
                      <option key={l} value={l}>{l.charAt(0).toUpperCase() + l.slice(1)}</option>
                    ))}
                  </select>
                </div>
                <div className="col-md-3">
                  <label className="form-label small mb-1">Channel</label>
                  <select name="channel" className="form-select form-select-sm js-live-filter"
                          value={eChannel} onChange={(e) => setFilter('channel', e.target.value)}>
                    <option value="">All Channels</option>
                    {errors.channels.map((ch) => (
                      <option key={ch} value={ch}>{ch.charAt(0).toUpperCase() + ch.slice(1)}</option>
                    ))}
                  </select>
                </div>
              </form>
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <CountHeader count={errors.logs.length} limit={errors.limit} />
            <div className="card-body p-0">
              {errors.logs.length === 0 ? (
                <p className="p-3 text-muted">No entries match the filter.</p>
              ) : (
                <div style={{ overflowX: 'auto' }}>
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr>
                        <th>Date / Time</th>
                        <th>Level</th>
                        <th>Channel</th>
                        <th>Message</th>
                        <th>User</th>
                        <th>Context</th>
                      </tr>
                    </thead>
                    <tbody>
                      {errors.logs.map((row) => (
                        <tr key={row.id}>
                          <td className="text-nowrap small text-muted">{fmtDateTime(row.logged_at)}</td>
                          <td>
                            <span className={`badge bg-${LEVEL_CLASSES[row.level] ?? 'secondary'}`}>
                              {row.level}
                            </span>
                          </td>
                          <td className="small text-muted">{row.channel}</td>
                          <td className="small">{row.message}</td>
                          <td className="small text-muted">{row.user_id !== null ? `#${row.user_id}` : '—'}</td>
                          <td className="small">
                            {row.context &&
                              Object.entries(row.context).map(([k, v]) => (
                                <span key={k}>
                                  <span className="text-muted">{k}:</span> <code>{String(v)}</code>{' '}
                                </span>
                              ))}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </>
      ) : mail ? (
        <>
          <div className="card border-0 shadow-sm mb-3">
            <div className="card-body py-2">
              <form className="row g-2 align-items-end" onSubmit={(e) => e.preventDefault()}>
                {timeframeSelect}
                <div className="col-md-3">
                  <label className="form-label small mb-1">Status</label>
                  <select name="status" className="form-select form-select-sm js-live-filter"
                          value={mStatus} onChange={(e) => setFilter('status', e.target.value)}>
                    <option value="">All</option>
                    <option value="sent">Sent</option>
                    <option value="failed">Failed</option>
                  </select>
                </div>
                <div className="col-md-3">
                  <label className="form-label small mb-1">Type</label>
                  <select name="type" className="form-select form-select-sm js-live-filter"
                          value={mType} onChange={(e) => setFilter('type', e.target.value)}>
                    <option value="">All Types</option>
                    {mail.types.map((t) => (
                      <option key={t} value={t}>{paymentType(t)}</option>
                    ))}
                  </select>
                </div>
              </form>
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <CountHeader count={mail.mails.length} limit={mail.limit} />
            <div className="card-body p-0">
              {mail.mails.length === 0 ? (
                <p className="p-3 text-muted">No entries match the filter.</p>
              ) : (
                <div style={{ overflowX: 'auto' }}>
                  <table className="table table-sm table-hover mb-0">
                    <thead className="table-light">
                      <tr>
                        <th>Date / Time</th>
                        <th>To</th>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {mail.mails.map((row) => (
                        <tr key={row.id}>
                          <td className="text-nowrap small text-muted">{fmtDateTime(row.sent_at)}</td>
                          <td className="small">{row.to_email}</td>
                          <td className="small">{row.subject}</td>
                          <td>
                            <span className="badge bg-secondary">{paymentType(row.type ?? '')}</span>
                          </td>
                          <td>
                            {row.status === 'sent' ? (
                              <span className="text-success fw-semibold small">✓ sent</span>
                            ) : (
                              <span className="text-danger fw-semibold small">✗ failed</span>
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
        </>
      ) : null}
    </>
  );
}
