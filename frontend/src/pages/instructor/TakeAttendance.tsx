// Take Attendance — React port of instructor/attendance.php. Keeps the old
// page's DOM contract (specs depend on it): #cb-N/.presence-cb checkboxes
// named present[], .att-row rows, #students-body etc. tbodies, #count-*
// badges tracking visible rows, #nameFilter hiding rows via inline display,
// a hidden input[name=session_date], and the Save/Delete buttons.

import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { AttendanceContext, AttendanceSaved, AttendanceStudent } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, personName } from '../../format';
import { fmtHeading, sortStudents } from './attendance-helpers';
import type { SortKey } from './attendance-helpers';

export default function TakeAttendance() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

  const dateParam = searchParams.get('date') ?? '';
  const sortParam = searchParams.get('sort') ?? '';
  const sort: SortKey = sortParam === 'last_name' || sortParam === 'last_attended' ? sortParam : 'first_name';
  const highlight = Number(searchParams.get('highlight') ?? 0);

  const [ctx, setCtx] = useState<AttendanceContext | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [present, setPresent] = useState<Set<number>>(new Set());
  const [instructorIds, setInstructorIds] = useState<Set<number>>(new Set());
  const [classType, setClassType] = useState('class');
  const [nameFilter, setNameFilter] = useState('');
  const [msg, setMsg] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const highlightDone = useRef(false);

  useEffect(() => {
    setCtx(null);
    setMsg(null);
    setNameFilter('');
    apiGet<AttendanceContext>(`/instructor/attendance.php?date=${encodeURIComponent(dateParam)}`)
      .then((data) => {
        setCtx(data);
        setClassType(data.class_type);
        setInstructorIds(new Set(data.selected_instructor_ids));
        const ids = new Set(data.students.filter((s) => s.present).map((s) => s.id));
        // Arriving from a profile page pre-checks that student
        if (highlight && !highlightDone.current) ids.add(highlight);
        setPresent(ids);
      })
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load attendance.'));
  }, [dateParam, highlight]);

  // Scroll + flash the highlighted row once the table exists
  useEffect(() => {
    if (!ctx || !highlight || highlightDone.current) return;
    highlightDone.current = true;
    const row = document.getElementById(`row-${highlight}`);
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    row.style.transition = 'background 0.3s';
    row.style.background = 'var(--bs-warning-bg-subtle, #fff3cd)';
    setTimeout(() => { row.style.background = ''; }, 2500);
  }, [ctx, highlight]);

  if (!ctx || error) return <PageState error={error} loading />;

  const date = ctx.date;

  function toggle(id: number) {
    setPresent((p) => {
      const next = new Set(p);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleInstructor(id: number) {
    setInstructorIds((p) => {
      const next = new Set(p);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function gotoDate(d: string) {
    if (!d) return;
    navigate(`/instructor/attendance?date=${d}${sort !== 'first_name' ? `&sort=${sort}` : ''}`);
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    try {
      const result = await apiPost<AttendanceSaved>('/instructor/attendance.php', {
        action: 'save',
        date,
        class_type: classType,
        present_ids: [...present],
        instructor_ids: [...instructorIds],
      });
      setMsg(result.removed
        ? `No students were marked present, so the class for ${fmtDate(date)} was removed.`
        : `Attendance saved for ${fmtDate(date)} — ${result.saved} present.`);
      const fresh = await apiGet<AttendanceContext>(`/instructor/attendance.php?date=${date}`);
      setCtx(fresh);
      setClassType(fresh.class_type);
      setInstructorIds(new Set(fresh.selected_instructor_ids));
      setPresent(new Set(fresh.students.filter((s) => s.present).map((s) => s.id)));
      window.scrollTo(0, 0);
    } catch (err: unknown) {
      setMsg(null);
      setError(err instanceof ApiError ? err.message : 'Could not save attendance.');
    } finally {
      setSaving(false);
    }
  }

  async function deleteSession() {
    const ok = window.confirm(
      `Delete the class for ${fmtDate(date)}?\n\nThis will remove all attendance records for this day and cannot be undone.`,
    );
    if (!ok) return;
    try {
      await apiPost('/instructor/attendance.php', { action: 'delete_session', date });
      navigate('/instructor');
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : 'Could not delete the class.');
    }
  }

  const q = nameFilter.toLowerCase();
  const matches = (s: AttendanceStudent) =>
    !q || `${s.first_name} ${s.last_name}`.toLowerCase().includes(q)
      || `${s.last_name}, ${s.first_name}`.toLowerCase().includes(q);

  const sections: [string, string, string, AttendanceStudent[]][] = [
    ['instructors', 'Instructors', '', ctx.students.filter((s) => s.student_type === 'instructor' || s.student_type === 'admin')],
    ['parents', 'Parents', '', ctx.students.filter((s) => s.student_type === 'parent')],
    ['students', 'Students', '', ctx.students.filter((s) => s.student_type === 'student')],
    ['guests', 'Guests', '(registration fee not yet paid)', ctx.students.filter((s) => s.student_type === 'guest')],
  ];

  const sortLink = (key: SortKey, label: string) => (
    <Link
      to={`/instructor/attendance?date=${date}&sort=${key}`}
      className={`btn btn-sm btn-filter ${sort === key ? 'active' : ''}`}
    >
      {label}
    </Link>
  );

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-3">
        <h4 className="mb-0" id="att-heading">Attendance — {fmtHeading(date)}</h4>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}

      <div className="d-flex gap-2 mb-3 align-items-center">
        <span className="text-muted small">Sort by:</span>
        {sortLink('first_name', 'First Name')}
        {sortLink('last_name', 'Last Name')}
        {sortLink('last_attended', 'Last Attended')}
        <input
          type="text"
          id="nameFilter"
          className="form-control form-control-sm ms-3"
          style={{ maxWidth: 200 }}
          placeholder="Filter by name…"
          value={nameFilter}
          onChange={(e) => setNameFilter(e.target.value)}
        />
      </div>

      <form id="att-form" onSubmit={(e) => void save(e)}>
        <input type="hidden" name="session_date" value={date} />

        <div className="card border-0 shadow-sm mb-3">
          <div className="card-body py-2">
            <div className="row g-3 align-items-end">
              <div className="col-auto">
                <label className="form-label small mb-1" htmlFor="sessionDateEdit">Class Date</label>
                <input
                  type="date"
                  name="session_date_edit"
                  id="sessionDateEdit"
                  className="form-control form-control-sm"
                  style={{ width: 160 }}
                  value={date}
                  onChange={(e) => gotoDate(e.target.value)}
                />
              </div>
              <div className="col-auto">
                <label className="form-label small mb-1" htmlFor="classType">Class Type</label>
                <select
                  name="class_type"
                  id="classType"
                  className="form-select form-select-sm"
                  style={{ width: 140 }}
                  value={classType}
                  onChange={(e) => setClassType(e.target.value)}
                >
                  <option value="class">Class</option>
                  <option value="seminar">Seminar</option>
                  <option value="private">Private</option>
                </select>
              </div>
              <div className="col-auto">
                <label className="form-label small mb-1 d-block">Taught by</label>
                <div className="d-flex flex-wrap gap-3 pt-1" id="instructorPicker">
                  {ctx.instructors.length === 0 ? (
                    <span className="text-muted small">No instructors on file.</span>
                  ) : (
                    ctx.instructors.map((ins) => (
                      <div className="form-check mb-0" key={ins.id}>
                        <input
                          type="checkbox"
                          className="form-check-input instructor-cb"
                          name="instructor_ids[]"
                          value={ins.id}
                          id={`instr-${ins.id}`}
                          checked={instructorIds.has(ins.id)}
                          onChange={() => toggleInstructor(ins.id)}
                        />
                        <label className="form-check-label small" htmlFor={`instr-${ins.id}`}>
                          {ins.name}
                        </label>
                      </div>
                    ))
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>

        {sections.map(([key, label, note, rows]) => {
          const visible = rows.filter(matches).length;
          return (
            <div className="card border-0 shadow-sm mb-4" id={`card-${key}`} key={key}
                 style={{ display: rows.length > 0 && visible === 0 ? 'none' : '' }}>
              <div className="card-header bg-white fw-semibold">
                {label} <span className="badge bg-primary" id={`count-${key}`}>{visible}</span>
                {note && <small className="text-muted fw-normal ms-2">{note}</small>}
              </div>
              <div className="card-body p-0">
                {rows.length === 0 ? (
                  <p className="p-3 text-muted">No {label.toLowerCase()}.</p>
                ) : (
                  <div className="table-responsive">
                    {/* Only the checkbox column gets an explicit (narrow) width
                        so it sits flush left. With the global table-layout:fixed
                        rule, the three unspecified columns (Name / Last Attended
                        / Waiver) split the remaining width equally, so their
                        gaps are uniform and Waiver ends flush right — identically
                        across all four role tables. */}
                    <table className="table table-sm table-hover mb-0">
                      <colgroup>
                        <col style={{ width: 44 }} />
                        <col />
                        <col />
                        <col />
                      </colgroup>
                      <thead className="table-light">
                        <tr>
                          <th className="text-center" aria-label="Present">✓</th>
                          <th>Name</th>
                          <th>Last Attended</th>
                          <th>Waiver</th>
                        </tr>
                      </thead>
                      <tbody id={`${key}-body`}>
                        {sortStudents(rows, sort).map((s) => (
                          <tr
                            key={s.id}
                            id={`row-${s.id}`}
                            className="att-row"
                            data-id={s.id}
                            style={{ cursor: 'pointer', display: matches(s) ? '' : 'none' }}
                            onClick={(e) => {
                              if ((e.target as HTMLElement).closest('.presence-cb')) return;
                              toggle(s.id);
                            }}
                          >
                            <td className="text-center">
                              <input
                                type="checkbox"
                                className="form-check-input presence-cb"
                                name="present[]"
                                value={s.id}
                                id={`cb-${s.id}`}
                                aria-label={`Present — ${personName(`${s.first_name} ${s.last_name}`)}`}
                                checked={present.has(s.id)}
                                onChange={() => toggle(s.id)}
                              />
                            </td>
                            <td className="row-name">
                              {sort === 'last_name'
                                ? `${personName(s.last_name)}, ${personName(s.first_name)}`
                                : personName(`${s.first_name} ${s.last_name}`)}
                            </td>
                            <td className="small">
                              {s.last_attended ? fmtDate(s.last_attended) : <em>never</em>}
                            </td>
                            <td>
                              {s.injury_waiver
                                ? <span className="text-success">✓</span>
                                : <span className="badge bg-warning text-dark">⚠ No waiver</span>}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          );
        })}
      </form>

      <div className="d-flex justify-content-between align-items-center mt-2">
        <button type="submit" form="att-form" className="btn btn-primary px-4" disabled={saving}>
          Save Attendance
        </button>
        {ctx.session_exists && (
          <button type="button" className="btn btn-outline-danger" onClick={() => void deleteSession()}>
            Delete This Class
          </button>
        )}
      </div>
    </>
  );
}
