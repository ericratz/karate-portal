// Belt test history — React port of parent/belt_tests.php.

import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiGet, ApiError } from '../api/client';
import type { BeltTestHistory } from '../api/types';
import { fmtDate } from '../format';
import { PageState, ScoreBadge, SubPageHeading } from '../components/shared';

export default function BeltTests() {
  const { id } = useParams();
  const studentId = Number(id);
  const [data, setData] = useState<BeltTestHistory | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiGet<BeltTestHistory>(`/parent/belt_tests.php?student_id=${studentId}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load belt tests.'));
  }, [studentId]);

  if (!data || error) return <PageState error={error} loading />;

  return (
    <>
      <SubPageHeading
        studentId={studentId}
        title="Belt Test History"
        name={`${data.student.first_name} ${data.student.last_name}`}
      />

      <div className="row g-3 mb-4">
        <div className="col-sm-4">
          <div className="card border-0 shadow-sm text-center">
            <div className="card-body">
              <div className="display-6 fw-bold text-primary">{data.tests.length}</div>
              <div className="text-muted small">Total Tests</div>
            </div>
          </div>
        </div>
        <div className="col-sm-4">
          <div className="card border-0 shadow-sm text-center">
            <div className="card-body">
              <div className="display-6 fw-bold text-success">{data.passed}</div>
              <div className="text-muted small">Passed</div>
            </div>
          </div>
        </div>
        <div className="col-sm-4">
          <div className="card border-0 shadow-sm text-center">
            <div className="card-body">
              <div className="display-6 fw-bold text-secondary">{data.pending}</div>
              <div className="text-muted small">Pending</div>
            </div>
          </div>
        </div>
      </div>

      <div className="card border-0 shadow-sm">
        <div className="card-header bg-white fw-semibold">
          All Belt Tests ({data.tests.length})
        </div>
        <div className="card-body p-0">
          {data.tests.length === 0 ? (
            <p className="p-3 text-muted">No belt tests on record yet.</p>
          ) : (
            <div className="table-responsive">
              <table className="table table-sm table-hover mb-0">
                <thead className="table-light">
                  <tr>
                    <th>#</th><th>Date</th><th>Testing For</th><th>Score</th>
                    <th>Fee</th><th>Test Passed</th>
                  </tr>
                </thead>
                <tbody>
                  {data.tests.map((t, i) => (
                    <tr key={`${t.test_date}-${i}`}>
                      <td className="text-muted small">{data.tests.length - i}</td>
                      <td>{fmtDate(t.test_date)}</td>
                      <td>{t.kyu_dan}</td>
                      <td><ScoreBadge result={t.result} score={t.score} /></td>
                      <td>{t.fee_paid ? <span className="text-success">✓</span> : ''}</td>
                      <td>
                        {t.result === 'pass' ? (
                          <span className="badge bg-success">Passed</span>
                        ) : t.result === 'fail' ? (
                          <span className="badge bg-danger">Failed</span>
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
    </>
  );
}
