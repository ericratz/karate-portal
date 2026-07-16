// All Belt Tests — React port of instructor/belt_tests_all.php: type-to-filter
// student picker, result/year filters (pushed into the route query), the
// Edit/Done toggle that reveals the delete column, and confirm-guarded
// deletes. Legacy DOM ids preserved for the specs (#editToggle,
// #beltTestsTable, .delete-col, #btFilterStudent*).

import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { InstructorBeltTestsData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, personName } from '../../format';

export default function BeltTestsAll() {
  const [searchParams, setSearchParams] = useSearchParams();
  const fStudent = Number(searchParams.get('student_id') ?? 0);
  const fResult = searchParams.get('result') ?? '';
  const fYear = searchParams.get('year') ?? '';
  const filtering = fStudent > 0 || fResult !== '' || fYear !== '';

  const [data, setData] = useState<InstructorBeltTestsData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState(false);
  const [pickerQuery, setPickerQuery] = useState('');

  useEffect(() => {
    setData(null);
    const qs = new URLSearchParams();
    if (fStudent) qs.set('student_id', String(fStudent));
    if (fResult) qs.set('result', fResult);
    if (fYear) qs.set('year', fYear);
    apiGet<InstructorBeltTestsData>(`/instructor/belt_tests.php?${qs.toString()}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load belt tests.'));
  }, [fStudent, fResult, fYear]);

  function setFilter(key: string, value: string) {
    const next = new URLSearchParams(searchParams);
    if (value) next.set(key, value);
    else next.delete(key);
    setSearchParams(next);
  }

  async function deleteTest(id: number) {
    if (!window.confirm('Delete this belt test record? This cannot be undone.')) return;
    try {
      await apiPost('/instructor/belt_tests.php', { action: 'delete', id });
      setData((d) => (d ? { ...d, tests: d.tests.filter((t) => t.id !== id) } : d));
    } catch (e: unknown) {
      setError(e instanceof ApiError ? e.message : 'Could not delete the test.');
    }
  }

  if (!data || error) {
    return (
      <>
        <div className="d-flex align-items-center gap-3 mb-4">
          <h4 className="mb-0">All Belt Tests</h4>
        </div>
        <PageState error={error} loading />
      </>
    );
  }

  const selectedName = fStudent
    ? (data.students.find((s) => s.id === fStudent)?.name ?? '')
    : '';
  const q = pickerQuery.toLowerCase().trim();
  const pickerMatches = q
    ? data.students.filter((s) => {
        const hay = `${s.name} ${s.name.split(' ').reverse().join(' ')}`.toLowerCase();
        return hay.includes(q);
      })
    : [];

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4">
        <h4 className="mb-0">All Belt Tests</h4>
        <a href="belt_test_edit.php" className="btn btn-success btn-sm ms-auto">+ New Test</a>
      </div>

      <div className="card border-0 shadow-sm mb-3">
        <div className="card-body py-2">
          <div className="row g-2 align-items-end">
            <div className="col-md-3">
              <label className="form-label small mb-1">Student</label>
              {fStudent > 0 && (
                <div id="btFilterStudentSelected" className="d-flex justify-content-between align-items-center mb-1">
                  <span className="small fw-semibold" id="btFilterStudentName">{personName(selectedName)}</span>
                  <button
                    type="button"
                    id="clearBtFilterStudentBtn"
                    className="btn btn-link btn-sm p-0 text-muted"
                    onClick={() => { setPickerQuery(''); setFilter('student_id', ''); }}
                  >
                    ×
                  </button>
                </div>
              )}
              <div className="stu-filter-wrap">
                {fStudent === 0 && (
                  <input
                    type="text"
                    id="btFilterStudentFilter"
                    className="form-control form-control-sm"
                    placeholder="Type to filter…"
                    autoComplete="off"
                    autoCorrect="off"
                    autoCapitalize="off"
                    spellCheck={false}
                    value={pickerQuery}
                    onChange={(e) => setPickerQuery(e.target.value)}
                  />
                )}
                {pickerMatches.length > 0 && fStudent === 0 && (
                  <div id="btFilterStudentList" className="list-group mt-1 stu-dropdown">
                    {pickerMatches.map((s) => (
                      <button
                        type="button"
                        key={s.id}
                        className="list-group-item list-group-item-action bt-filter-stu-btn"
                        onClick={() => { setPickerQuery(''); setFilter('student_id', String(s.id)); }}
                      >
                        {personName(s.name)}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            </div>
            <div className="col-md-2">
              <label className="form-label small mb-1" htmlFor="btResultFilter">Result</label>
              <select
                name="result"
                id="btResultFilter"
                className="form-select form-select-sm"
                value={fResult}
                onChange={(e) => setFilter('result', e.target.value)}
              >
                <option value="">All</option>
                <option value="pending">Pending</option>
                <option value="pass">Pass</option>
                <option value="fail">Fail</option>
              </select>
            </div>
            <div className="col-md-2">
              <label className="form-label small mb-1" htmlFor="btYearFilter">Year</label>
              <select
                name="year"
                id="btYearFilter"
                className="form-select form-select-sm"
                value={fYear}
                onChange={(e) => setFilter('year', e.target.value)}
              >
                <option value="">All Years</option>
                {data.years.map((y) => <option key={y} value={String(y)}>{y}</option>)}
              </select>
            </div>
            {filtering && (
              <div className="col-auto">
                <Link to="/instructor/belt-tests" className="btn btn-filter btn-sm">Clear</Link>
              </div>
            )}
          </div>
        </div>
      </div>

      <div id="belt-tests-results" className="card border-0 shadow-sm">
        <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
          <span>{data.tests.length} test{data.tests.length !== 1 ? 's' : ''}</span>
          {data.tests.length > 0 && (
            <button
              id="editToggle"
              className={editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary'}
              onClick={() => setEditing(!editing)}
            >
              {editing ? 'Done' : 'Edit'}
            </button>
          )}
        </div>
        <div className="card-body p-0">
          {data.tests.length === 0 ? (
            <p className="p-3 text-muted">No belt tests match the filter.</p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
              <table id="beltTestsTable" className={`table table-hover mb-0 align-middle ${editing ? 'editing' : ''}`}>
                <thead className="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>Testing For</th>
                    <th>Score</th>
                    <th className="text-center">Fee Paid</th>
                    <th className="text-center">Test Passed</th>
                    <th>Notes</th>
                    <th></th>
                    <th className="delete-col"></th>
                  </tr>
                </thead>
                <tbody>
                  {data.tests.map((t) => (
                    <tr key={t.id}>
                      <td className="text-nowrap">{fmtDate(t.test_date)}</td>
                      <td>
                        <a href={`student_profile.php?id=${t.student_id}`}>{personName(t.student)}</a>
                      </td>
                      <td>{t.kyu_dan}</td>
                      <td>
                        {t.score !== null ? (
                          <span className={`badge ${t.result === 'pass' ? 'bg-success' : 'bg-danger'}`}>
                            {t.score}%
                          </span>
                        ) : (
                          <span className="badge bg-secondary">Pending</span>
                        )}
                      </td>
                      <td className="text-center">
                        {t.fee_paid ? <span className="text-success">✓</span> : <span className="text-muted">—</span>}
                      </td>
                      <td className="text-center">
                        {t.result === 'pass' ? (
                          <span className="badge bg-success">Passed</span>
                        ) : t.result === 'fail' ? (
                          <span className="text-danger">✗</span>
                        ) : (
                          <span className="text-muted">—</span>
                        )}
                      </td>
                      <td className="text-muted small">{t.notes ?? ''}</td>
                      <td>
                        {data.is_admin && (
                          <a
                            href={`belt_test_edit.php?id=${t.id}&ref_pid=${t.student_id}`}
                            className="btn btn-sm btn-outline-primary"
                          >
                            Edit
                          </a>
                        )}
                      </td>
                      <td className="delete-col">
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-danger py-0"
                          onClick={() => void deleteTest(t.id)}
                        >
                          ✕
                        </button>
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
