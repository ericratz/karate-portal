// Class Notes — React port of admin/student_notes.php. Without ?student_id=
// it's the combined page: students-with-notes roster (live name search) plus
// the general class-notes log with its highlight search, Ctrl+F capture, and
// Edit-toggle delete reveal. With ?student_id=N it's that student's notes.

import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { ClassNotesData, StudentNotesData } from '../../api/types';
import { PageState } from '../../components/shared';
import { fmtDate, fmtDateTime, personName } from '../../format';

const GROUPS: [string, string, (t: string) => boolean][] = [
  ['instructors', 'Instructors', (t) => t === 'instructor' || t === 'admin'],
  ['parents', 'Parents', (t) => t === 'parent'],
  ['students', 'Students', (t) => t === 'student'],
  ['guests', 'Guests', (t) => t === 'guest'],
];

/** One general-notes entry with its inline edit form (same DOM as the PHP page). */
function GeneralNoteEntry({
  n,
  highlight,
  onSave,
  onDelete,
}: {
  n: { id: number; content: string; created_at: string; username: string | null };
  highlight: string;
  onSave: (content: string) => Promise<boolean>;
  onDelete: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [content, setContent] = useState(n.content);

  // Wrap search matches in <mark>, like the old page's innerHTML highlighter
  const renderContent = () => {
    const q = highlight.trim();
    if (!q) return n.content;
    const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const parts = n.content.split(new RegExp(`(${escaped})`, 'gi'));
    return parts.map((part, i) =>
      part.toLowerCase() === q.toLowerCase() ? (
        <mark key={i} style={{ background: '#fff3cd', padding: '0 2px' }}>{part}</mark>
      ) : (
        part
      ),
    );
  };

  return (
    <div className="note-entry border-bottom p-3" data-id={n.id}>
      <div className="d-flex justify-content-between align-items-start mb-1">
        <span>
          <strong>{fmtDate(n.created_at)}</strong> — {n.username ?? 'unknown'}
        </span>
        <div className="d-flex gap-1 flex-shrink-0 ms-2">
          <button
            type="button"
            className="btn btn-sm btn-outline-secondary py-0 note-edit-btn"
            onClick={() => setEditing(true)}
          >
            Edit
          </button>
          <span className="delete-btn">
            <button
              type="button"
              className="btn btn-sm btn-outline-danger py-0"
              onClick={onDelete}
            >
              ✕
            </button>
          </span>
        </div>
      </div>
      <div className="note-content" style={{ whiteSpace: 'pre-line' }}>{renderContent()}</div>
      <form
        className="note-edit-form mt-2"
        style={{ display: editing ? '' : 'none' }}
        onSubmit={(e) => {
          e.preventDefault();
          if (content.trim()) {
            void onSave(content).then((ok) => {
              if (ok) setEditing(false);
            });
          }
        }}
      >
        <textarea
          name="content"
          className="form-control form-control-sm mb-2"
          rows={4}
          required
          value={content}
          onChange={(e) => setContent(e.target.value)}
        />
        <div className="d-flex gap-2">
          <button type="submit" className="btn btn-sm btn-primary">Save</button>
          <button type="button" className="btn btn-sm btn-secondary note-cancel-btn" onClick={() => setEditing(false)}>
            Cancel
          </button>
        </div>
      </form>
    </div>
  );
}

function StudentNotesView({ studentId }: { studentId: number }) {
  const [data, setData] = useState<StudentNotesData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState('');
  const [msg, setMsg] = useState('');
  const [content, setContent] = useState('');
  const [editing, setEditing] = useState(false);

  const load = useCallback(() => {
    apiGet<StudentNotesData>(`/admin/student_notes.php?student_id=${studentId}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the notes.'));
  }, [studentId]);

  useEffect(load, [load]);

  if (!data || error) return <PageState error={error} loading />;

  const post = async (payload: Record<string, unknown>): Promise<boolean> => {
    setActionError('');
    try {
      await apiPost('/admin/student_notes.php', { student_id: studentId, ...payload });
      load();
      return true;
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'The action failed.');
      return false;
    }
  };

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4">
        <Link to="/admin/notes" className="btn btn-outline-secondary btn-sm">← Class Notes</Link>
        <h4 className="mb-0">Notes — {personName(data.student.name)}</h4>
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {actionError && <div className="alert alert-danger">{actionError}</div>}

      <div className="row g-4">
        <div className="col-md-5">
          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Add Note</div>
            <div className="card-body">
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  if (content.trim()) {
                    void post({ action: 'add', content }).then((ok) => {
                      if (ok) {
                        setContent('');
                        setMsg('Note added.');
                      }
                    });
                  }
                }}
              >
                <div className="mb-3">
                  <textarea
                    name="content"
                    className="form-control"
                    rows={5}
                    placeholder="Enter note…"
                    required
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                  />
                </div>
                <button className="btn btn-primary">Save Note</button>
              </form>
            </div>
          </div>
        </div>

        <div className="col-md-7">
          <div id="student-notes-card" className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
              <span>All Notes ({data.notes.length})</span>
              {data.notes.length > 0 && (
                <button
                  id="editToggle"
                  className={editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-outline-secondary'}
                  onClick={() => setEditing((v) => !v)}
                >
                  {editing ? 'Done' : 'Edit'}
                </button>
              )}
            </div>
            <div id="notesContainer" className={`card-body p-0${editing ? ' editing' : ''}`} style={{ maxHeight: 500, overflowY: 'auto' }}>
              {data.notes.length === 0 ? (
                <p className="p-3 text-muted">No notes yet.</p>
              ) : (
                data.notes.map((n) => (
                  <div className="note-entry border-bottom p-3" key={n.id}>
                    <div className="d-flex justify-content-between align-items-start">
                      <span>
                        {fmtDateTime(n.created_at)} by <strong>{n.username ?? 'unknown'}</strong>
                      </span>
                      <span className="delete-btn">
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-danger py-0 ms-2"
                          onClick={() => {
                            if (window.confirm('Delete this note?')) {
                              void post({ action: 'delete', id: n.id });
                            }
                          }}
                        >
                          ✕
                        </button>
                      </span>
                    </div>
                    <p className="mb-0 mt-1" style={{ whiteSpace: 'pre-line' }}>{n.content}</p>
                  </div>
                ))
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

function CombinedView() {
  const [data, setData] = useState<ClassNotesData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState('');
  const [msg, setMsg] = useState('');
  const [rosterQuery, setRosterQuery] = useState('');
  const [addOpen, setAddOpen] = useState(false);
  const [newContent, setNewContent] = useState('');
  const [editing, setEditing] = useState(false);
  const [noteQuery, setNoteQuery] = useState('');
  const searchRef = useRef<HTMLInputElement>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  const load = useCallback(() => {
    apiGet<ClassNotesData>('/admin/student_notes.php')
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load class notes.'));
  }, []);

  useEffect(load, [load]);

  // Ctrl+F focuses the notes search, like the old page
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        if (searchRef.current) {
          e.preventDefault();
          searchRef.current.focus();
          searchRef.current.select();
        }
      }
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, []);

  if (!data || error) return <PageState error={error} loading />;

  const post = async (payload: Record<string, unknown>): Promise<boolean> => {
    setActionError('');
    try {
      await apiPost('/admin/student_notes.php', payload);
      load();
      return true;
    } catch (e: unknown) {
      setActionError(e instanceof ApiError ? e.message : 'The action failed.');
      return false;
    }
  };

  const rosterMatches = (first: string, last: string) => {
    const q = rosterQuery.toLowerCase().trim();
    return !q || `${last} ${first} ${first} ${last}`.toLowerCase().includes(q);
  };

  const q = noteQuery.trim().toLowerCase();
  const visibleNotes = data.class_notes.filter((n) => !q || n.content.toLowerCase().includes(q));

  return (
    <>
      <div className="d-flex align-items-center justify-content-between mb-4">
        <h3 className="mb-0">Class Notes</h3>
        <input
          type="text"
          id="rosterSearch"
          className="form-control form-control-sm"
          placeholder="Search name…"
          style={{ width: 200 }}
          value={rosterQuery}
          onChange={(e) => setRosterQuery(e.target.value)}
        />
      </div>

      {msg && <div className="alert alert-success">{msg}</div>}
      {actionError && <div className="alert alert-danger">{actionError}</div>}

      {GROUPS.map(([key, label, match]) => {
        const rows = data.students.filter((st) => match(st.student_type));
        if (rows.length === 0) return null;
        const visible = rows.filter((st) => rosterMatches(st.first_name, st.last_name));
        return (
          <div
            className="card border-0 shadow-sm mb-4"
            id={`card-${key}`}
            key={key}
            style={{ display: visible.length === 0 ? 'none' : '' }}
          >
            <div className="card-header bg-white fw-semibold">
              {label} <span className="badge bg-primary ms-2" id={`count-${key}`}>{visible.length}</span>
            </div>
            <div className="card-body p-0">
              <div className="table-responsive">
                <table className="table table-sm table-hover mb-0" style={{ tableLayout: 'fixed', width: '100%', minWidth: 560 }}>
                  {/* Only the trailing action (View) column gets a fixed
                      width; the four data columns split the rest equally via
                      the global table-layout:fixed rule, so their gaps match. */}
                  <colgroup>
                    <col /><col /><col /><col />
                    <col style={{ width: 80 }} />
                  </colgroup>
                  <thead className="table-light">
                    <tr>
                      <th>Name</th><th>Last Attended</th><th>Status</th><th>Notes</th><th />
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((st) => (
                      <tr
                        key={st.id}
                        data-name={`${st.last_name} ${st.first_name} ${st.first_name} ${st.last_name}`.toLowerCase()}
                        style={{ display: rosterMatches(st.first_name, st.last_name) ? '' : 'none' }}
                      >
                        <td className="fw-semibold">
                          <Link to={`/admin/notes?student_id=${st.id}`} className="text-decoration-none">
                            {personName(`${st.first_name} ${st.last_name}`)}
                          </Link>
                        </td>
                        <td>{st.last_attended ? fmtDate(st.last_attended) : 'Never'}</td>
                        <td>
                          {st.active ? (
                            <span className="badge bg-success">Active</span>
                          ) : (
                            <span className="badge bg-secondary">Inactive</span>
                          )}
                          {st.active_override && <> <span className="badge bg-warning text-dark">Override</span></>}
                        </td>
                        <td>{st.note_count}</td>
                        <td>
                          <Link to={`/admin/notes?student_id=${st.id}`} className="btn btn-sm btn-outline-primary">
                            View
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        );
      })}

      {data.students.length === 0 && <p className="text-muted">No student notes yet.</p>}

      {/* General class notes */}
      <div className="d-flex align-items-center justify-content-between mb-3 mt-5">
        <h5 className="mb-0">General Class Notes</h5>
        <button className="btn btn-success btn-sm" onClick={() => setAddOpen((v) => !v)}>
          + Add Entry
        </button>
      </div>

      <div className={`collapse mb-4${addOpen ? ' show' : ''}`} id="addEntryBox">
        <div className="card border-0 shadow-sm">
          <div className="card-body">
            <form
              onSubmit={(e) => {
                e.preventDefault();
                if (newContent.trim()) {
                  void post({ action: 'add', content: newContent }).then((ok) => {
                    if (ok) {
                      setNewContent('');
                      setMsg('Note saved.');
                      // Scroll the log to the newest (bottom) entry once it re-renders
                      setTimeout(() => {
                        const c = containerRef.current;
                        if (c) c.scrollTop = c.scrollHeight;
                      }, 300);
                    }
                  });
                }
              }}
            >
              <div className="mb-3">
                <textarea
                  name="content"
                  className="form-control"
                  rows={4}
                  placeholder="Class notes, reminders, announcements…"
                  required
                  value={newContent}
                  onChange={(e) => setNewContent(e.target.value)}
                />
              </div>
              <button className="btn btn-primary">Save Entry</button>
            </form>
          </div>
        </div>
      </div>

      <div id="general-notes-card" className="card border-0 shadow-sm">
        <div className="card-header bg-white d-flex justify-content-between align-items-center">
          <span className="fw-semibold">Notes Log ({data.class_notes.length} entries)</span>
          <div className="d-flex gap-2 align-items-center">
            <input
              ref={searchRef}
              type="text"
              id="noteSearch"
              className="form-control form-control-sm"
              style={{ width: 200 }}
              placeholder="Search notes… (Ctrl+F)"
              value={noteQuery}
              onChange={(e) => setNoteQuery(e.target.value)}
            />
            <span id="matchCount" className="text-muted small">
              {q ? `${visibleNotes.length} match${visibleNotes.length !== 1 ? 'es' : ''}` : ''}
            </span>
            {data.class_notes.length > 0 && (
              <button
                id="editToggle"
                className={editing ? 'btn btn-sm btn-danger' : 'btn btn-sm btn-success'}
                onClick={() => setEditing((v) => !v)}
              >
                {editing ? 'Done' : 'Edit'}
              </button>
            )}
          </div>
        </div>

        <div
          ref={containerRef}
          id="notesContainer"
          className={`card-body p-0${editing ? ' editing' : ''}`}
          style={{ maxHeight: '60vh', overflowY: 'auto' }}
        >
          {data.class_notes.length === 0 ? (
            <p className="p-3 text-muted">No notes yet.</p>
          ) : (
            data.class_notes.map((n) => (
              <div key={n.id} style={{ display: !q || n.content.toLowerCase().includes(q) ? '' : 'none' }}>
                <GeneralNoteEntry
                  n={n}
                  highlight={noteQuery}
                  onSave={(content) => post({ action: 'edit', id: n.id, content })}
                  onDelete={() => {
                    if (window.confirm('Delete this entry?')) {
                      void post({ action: 'delete', id: n.id });
                    }
                  }}
                />
              </div>
            ))
          )}
        </div>
      </div>
    </>
  );
}

export default function ClassNotes() {
  const [searchParams] = useSearchParams();
  const studentId = Number(searchParams.get('student_id') ?? 0);
  return studentId ? <StudentNotesView studentId={studentId} /> : <CombinedView />;
}
