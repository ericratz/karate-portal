// Exemptions (payment waivers) — React port of admin/waivers.php: grant form
// with a required type-to-filter student picker, filter bar (student/type/year,
// server-side filtering like the old WHERE clauses), and the exemptions table
// with the Edit-toggle delete column. Filters live in the route query so URLs
// stay shareable like hx-push-url kept them.

import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { AdminExemptionsData } from '../../api/types';
import { PageState } from '../../components/shared';
import StudentPicker from '../../components/StudentPicker';
import type { StudentPickerHandle } from '../../components/StudentPicker';
import { fmtDate, personName } from '../../format';

const TYPE_LABELS: Record<string, string> = {
  monthly_tuition: 'Monthly Tuition',
  registration: 'Registration Fee',
  belt_test: 'Belt Test Fee',
  slc_training: 'SLC Training',
  seminar: 'Seminar',
  all: 'All Fees',
};

export default function Exemptions() {
  const [searchParams, setSearchParams] = useSearchParams();
  const fStudent = Number(searchParams.get('student_id') ?? 0);
  const fType = searchParams.get('type') ?? '';
  const fYear = Number(searchParams.get('year') ?? 0);

  const [data, setData] = useState<AdminExemptionsData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState('');
  const [formError, setFormError] = useState('');
  const [editing, setEditing] = useState(false);

  // Grant form state
  const [grantStudent, setGrantStudent] = useState<{ id: number; label: string } | null>(null);
  const [waiverType, setWaiverType] = useState('monthly_tuition');
  const [reason, setReason] = useState('');
  const [grantedDate, setGrantedDate] = useState(() => new Date().toISOString().slice(0, 10));
  const grantPicker = useRef<StudentPickerHandle>(null);

  const load = useCallback(() => {
    const qs = new URLSearchParams();
    if (fStudent) qs.set('student_id', String(fStudent));
    if (fType) qs.set('type', fType);
    if (fYear) qs.set('year', String(fYear));
    const suffix = qs.toString() ? `?${qs.toString()}` : '';
    apiGet<AdminExemptionsData>(`/admin/waivers.php${suffix}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load exemptions.'));
  }, [fStudent, fType, fYear]);

  useEffect(load, [load]);

  const setFilter = (key: string, value: string) => {
    setSearchParams((prev) => {
      const next = new URLSearchParams(prev);
      if (value) next.set(key, value);
      else next.delete(key);
      return next;
    });
  };

  const grant = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!grantStudent) {
      grantPicker.current?.reportMissing('Please select a student.');
      return;
    }
    setFormError('');
    try {
      await apiPost('/admin/waivers.php', {
        action: 'grant',
        student_id: grantStudent.id,
        waiver_type: waiverType,
        reason,
        granted_date: grantedDate,
      });
      setMsg('Exemption granted.');
      setGrantStudent(null);
      setReason('');
      setWaiverType('monthly_tuition');
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not grant the exemption.');
    }
  };

  const remove = async (id: number) => {
    if (!window.confirm('Permanently delete this exemption? This cannot be undone.')) return;
    try {
      await apiPost('/admin/waivers.php', { action: 'delete', id });
      load();
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not delete the exemption.');
    }
  };

  if (!data || error) return <PageState error={error} loading />;

  const filterStudentName = fStudent
    ? data.students.find((s) => s.id === fStudent)
    : null;
  const filtering = fStudent !== 0 || fType !== '' || fYear !== 0;

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h3 className="mb-0">Exemptions</h3>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {formError && <div className="alert alert-danger">{formError}</div>}

      <div className="row g-4">
        {/* Grant form */}
        <div className="col-md-4">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Grant Exemption</div>
            <div className="card-body">
              <form id="grantForm" onSubmit={grant} noValidate>
                <div className="mb-3">
                  <label className="form-label">Student *</label>
                  <StudentPicker
                    ref={grantPicker}
                    students={data.students}
                    idBase="grantStudent"
                    btnClass="grant-stu-btn"
                    selected={grantStudent}
                    onSelect={(id, label) => setGrantStudent({ id, label })}
                    onClear={() => setGrantStudent(null)}
                    placeholder="Type student name…"
                    required
                  />
                </div>
                <div className="mb-3">
                  <label className="form-label">Waiver Type *</label>
                  <select
                    name="waiver_type"
                    className="form-select"
                    required
                    value={waiverType}
                    onChange={(e) => setWaiverType(e.target.value)}
                  >
                    {Object.entries(TYPE_LABELS).map(([v, l]) => (
                      <option key={v} value={v}>{l}</option>
                    ))}
                  </select>
                </div>
                <div className="mb-3">
                  <label className="form-label">Reason</label>
                  <textarea
                    name="reason"
                    className="form-control"
                    rows={3}
                    placeholder="Financial hardship, scholarship, etc."
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                  />
                </div>
                <div className="mb-3">
                  <label className="form-label">Granted Date</label>
                  <input
                    type="date"
                    name="granted_date"
                    className="form-control"
                    value={grantedDate}
                    onChange={(e) => setGrantedDate(e.target.value)}
                  />
                </div>
                <button name="grant" className="btn btn-success w-100">Grant Exemption</button>
              </form>
            </div>
          </div>
        </div>

        {/* Filter bar + list */}
        <div className="col-md-8">
          <div id="waivers-page-body">
            <div className="card border-0 shadow-sm mb-3">
              <div className="card-body py-2">
                <form id="filterForm" className="row g-2 align-items-end" onSubmit={(e) => e.preventDefault()}>
                  <div className="col-md-3">
                    <label className="form-label small mb-1">Student</label>
                    <StudentPicker
                      students={data.students}
                      idBase="filterStudent"
                      btnClass="filter-stu-btn"
                      selected={
                        filterStudentName
                          ? { id: filterStudentName.id, label: `${filterStudentName.first_name} ${filterStudentName.last_name}` }
                          : null
                      }
                      onSelect={(id) => setFilter('student_id', String(id))}
                      onClear={() => setFilter('student_id', '')}
                      placeholder="Type to filter…"
                      clearLabel="×"
                      small
                    />
                  </div>
                  <div className="col-md-3">
                    <label className="form-label small mb-1">Type</label>
                    <select
                      name="type"
                      className="form-select form-select-sm"
                      value={fType}
                      onChange={(e) => setFilter('type', e.target.value)}
                    >
                      <option value="">All Types</option>
                      {Object.entries(TYPE_LABELS).map(([v, l]) => (
                        <option key={v} value={v}>{l}</option>
                      ))}
                    </select>
                  </div>
                  <div className="col-md-3">
                    <label className="form-label small mb-1">Year</label>
                    <select
                      name="year"
                      className="form-select form-select-sm"
                      value={fYear || ''}
                      onChange={(e) => setFilter('year', e.target.value)}
                    >
                      <option value="">All Years</option>
                      {data.years.map((y) => (
                        <option key={y} value={y}>{y}</option>
                      ))}
                    </select>
                  </div>
                  {filtering && (
                    <div className="col-auto">
                      <button
                        type="button"
                        className="btn btn-filter btn-sm"
                        onClick={() => setSearchParams(new URLSearchParams())}
                      >
                        Clear
                      </button>
                    </div>
                  )}
                </form>
              </div>
            </div>

            <div id="waivers-results" className="card border-0 shadow-sm">
              <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>All Exemptions ({data.waivers.length})</span>
                {data.waivers.length > 0 && (
                  <button
                    id="editToggle"
                    className={editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary'}
                    onClick={() => setEditing((v) => !v)}
                  >
                    {editing ? 'Done' : 'Edit'}
                  </button>
                )}
              </div>
              <div className="card-body p-0">
                {data.waivers.length === 0 ? (
                  <p className="p-3 text-muted">No waivers match the filter.</p>
                ) : (
                  <div style={{ overflowX: 'auto' }}>
                    <table id="waiversTable" className={`table table-hover mb-0${editing ? ' editing' : ''}`}>
                      <thead className="table-light">
                        <tr>
                          <th>Student</th>
                          <th>Type</th>
                          <th>Reason</th>
                          <th>Granted</th>
                          <th className="delete-col" />
                        </tr>
                      </thead>
                      <tbody>
                        {data.waivers.map((w) => (
                          <tr key={w.id}>
                            <td>
                              <a href={`student_edit.php?id=${w.student_id}`} className="text-decoration-none">
                                {personName(w.student_name)}
                              </a>
                            </td>
                            <td>{TYPE_LABELS[w.waiver_type] ?? w.waiver_type}</td>
                            <td>{w.reason ?? '—'}</td>
                            <td>{fmtDate(w.granted_date)}</td>
                            <td className="delete-col">
                              <button
                                type="button"
                                className="btn btn-sm btn-outline-danger py-0"
                                onClick={() => void remove(w.id)}
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
          </div>
        </div>
      </div>
    </>
  );
}
