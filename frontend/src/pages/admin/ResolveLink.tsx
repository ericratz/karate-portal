// Resolve Linking — React port of admin/resolve_link.php: the new-account
// and auto-created-duplicate info cards, candidate search, radio-pick table,
// and the link-to-real-record action (server-side transaction deletes the
// duplicate guest record and resolves the alert).

import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { ResolveLinkData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, personName } from '../../format';

export default function ResolveLink() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const lrId = Number(searchParams.get('lr_id') ?? 0);

  const [data, setData] = useState<ResolveLinkData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState('');
  const [query, setQuery] = useState('');
  const [pickedId, setPickedId] = useState<number | null>(null);

  useEffect(() => {
    if (!lrId) {
      navigate('/admin', { replace: true });
      return;
    }
    apiGet<ResolveLinkData>(`/admin/resolve_link.php?lr_id=${lrId}`)
      .then(setData)
      .catch((e: unknown) => {
        if (e instanceof ApiError && e.status === 404) {
          // Same URL the old PHP page redirected with for an unknown lr_id
          navigate('/admin?error=not_found', { replace: true });
          return;
        }
        setError(e instanceof ApiError ? e.message : 'Could not load the link request.');
      });
  }, [lrId, navigate]);

  if (!data || error) return <PageState error={error} loading />;

  const req = data.request;
  const q = query.toLowerCase().trim();
  const matches = (c: ResolveLinkData['candidates'][number]) =>
    !q || `${c.first_name} ${c.last_name} ${c.email ?? ''}`.toLowerCase().includes(q);

  const resolve = async () => {
    if (!pickedId) return;
    setActionError('');
    try {
      await apiPost('/admin/resolve_link.php', { lr_id: lrId, real_student_id: pickedId });
      navigate('/admin?linked=1');
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'Link resolution failed.');
    }
  };

  return (
    <>
      <div className="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" className="btn btn-sm btn-outline-secondary">← Dashboard</a>
        <h4 className="mb-0">Resolve Linking</h4>
      </div>

      {actionError && <div className="alert alert-danger">{actionError}</div>}

      <div className="row g-4">
        {/* Left: user & auto-created record info */}
        <div className="col-lg-5">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">New Account</div>
            <div className="card-body">
              <dl className="row mb-0 small">
                <dt className="col-5 text-muted">Username</dt>
                <dd className="col-7">{req.username}</dd>
                <dt className="col-5 text-muted">Name</dt>
                <dd className="col-7">{req.user_name}</dd>
                <dt className="col-5 text-muted">Email</dt>
                <dd className="col-7">{req.user_email}</dd>
                {req.user_dob && (
                  <>
                    <dt className="col-5 text-muted">Date of Birth</dt>
                    <dd className="col-7">{fmtDate(req.user_dob)}</dd>
                  </>
                )}
                <dt className="col-5 text-muted">Registered</dt>
                <dd className="col-7">{fmtDate(req.created_at)}</dd>
              </dl>
            </div>
          </div>

          {req.duplicate && (
            <div className="card border-0 shadow-sm mt-3">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                Auto-Created Record
                <span className="badge bg-secondary">Will be deleted</span>
              </div>
              <div className="card-body">
                <dl className="row mb-0 small">
                  <dt className="col-5 text-muted">Name</dt>
                  <dd className="col-7">{req.duplicate.name}</dd>
                  {req.duplicate.date_of_birth && (
                    <>
                      <dt className="col-5 text-muted">Date of Birth</dt>
                      <dd className="col-7">{fmtDate(req.duplicate.date_of_birth)}</dd>
                    </>
                  )}
                  {req.duplicate.email && (
                    <>
                      <dt className="col-5 text-muted">Email</dt>
                      <dd className="col-7">{req.duplicate.email}</dd>
                    </>
                  )}
                  <dt className="col-5 text-muted">Type</dt>
                  <dd className="col-7">
                    <span className="badge bg-secondary">{req.duplicate.student_type ?? '—'}</span>
                  </dd>
                </dl>
                <div className="alert alert-warning py-1 small mt-2 mb-0">
                  This temporary record will be deleted once you link the user to their real record.
                </div>
              </div>
            </div>
          )}

          <div className="alert alert-info small mt-3">
            <strong>What happens:</strong> The user's login gets linked to the selected real student record.
            Their temporary auto-created record is deleted. Their account type is determined by the linked
            student record.
          </div>
        </div>

        {/* Right: candidate picker */}
        <div className="col-lg-7">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>Select the Real Student Record</span>
              <span className="text-muted small">
                {data.candidates.length} unlinked record{data.candidates.length !== 1 ? 's' : ''}
              </span>
            </div>
            <div className="card-body">
              {data.candidates.length === 0 ? (
                <p className="text-muted mb-0">No unlinked student records found.</p>
              ) : (
                <>
                  <input
                    type="text"
                    id="candidateSearch"
                    className="form-control form-control-sm mb-3"
                    placeholder="Search by name or email…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                  />

                  <form
                    id="resolveForm"
                    onSubmit={(e) => {
                      e.preventDefault();
                      void resolve();
                    }}
                  >
                    <div className="table-responsive" style={{ maxHeight: 420, overflowY: 'auto' }}>
                      <table className="table table-sm table-hover mb-0" id="candidateTable">
                        <thead className="table-light sticky-top">
                          <tr>
                            <th />
                            <th>Name</th>
                            <th>DOB</th>
                            <th>Belt</th>
                            <th>Type</th>
                          </tr>
                        </thead>
                        <tbody>
                          {data.candidates.map((c) => (
                            <tr
                              key={c.id}
                              className="candidate-row"
                              style={{ display: matches(c) ? '' : 'none' }}
                            >
                              <td>
                                <input
                                  type="radio"
                                  name="radio_pick"
                                  className="candidate-radio"
                                  value={c.id}
                                  checked={pickedId === c.id}
                                  onChange={() => setPickedId(c.id)}
                                />
                              </td>
                              <td>
                                <div className="fw-semibold">{personName(`${c.first_name} ${c.last_name}`)}</div>
                                {c.email && <div className="text-muted small">{c.email}</div>}
                              </td>
                              <td className="small text-nowrap">
                                {c.date_of_birth ? fmtDate(c.date_of_birth) : '—'}
                              </td>
                              <td className="small">{c.rank_name ?? '—'}</td>
                              <td>
                                <span className="badge bg-secondary" style={{ fontSize: '.7rem' }}>
                                  {c.student_type}
                                </span>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    <button type="submit" id="confirmBtn" className="btn btn-warning w-100 mt-3" disabled={!pickedId}>
                      Link Account to Selected Record
                    </button>
                  </form>
                </>
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
