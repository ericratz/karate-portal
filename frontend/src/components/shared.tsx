// Small shared pieces used across the parent pages.

import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useSession } from '../SessionContext';
import { personName } from '../format';

/** External-link arrow icon (the inline SVG from the PHP pages). */
export function ExtIcon({ size = 13 }: { size?: number }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width={size}
      height={size}
      fill="currentColor"
      viewBox="0 0 16 16"
      style={{ verticalAlign: 'middle', marginLeft: 3 }}
      aria-hidden="true"
    >
      <path fillRule="evenodd" d="M8.636 3.5a.5.5 0 0 0-.5-.5H1.5A1.5 1.5 0 0 0 0 4.5v10A1.5 1.5 0 0 0 1.5 16h10a1.5 1.5 0 0 0 1.5-1.5V7.864a.5.5 0 0 0-1 0V14.5a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h6.636a.5.5 0 0 0 .5-.5" />
      <path fillRule="evenodd" d="M16 .5a.5.5 0 0 0-.5-.5h-5a.5.5 0 0 0 0 1h3.793L6.146 9.146a.5.5 0 1 0 .708.708L15 1.707V5.5a.5.5 0 0 0 1 0z" />
    </svg>
  );
}

/** Centered stat card used in the summary rows. */
export function StatCard({ value, label }: { value: ReactNode; label: string }) {
  return (
    <div className="col-sm-6 col-lg-3">
      <div className="card text-center h-100 border-0 shadow-sm">
        <div className="card-body d-flex flex-column align-items-center justify-content-center gap-1">
          <div className="fs-3 fw-bold">{value}</div>
          <div className="text-muted small">{label}</div>
        </div>
      </div>
    </div>
  );
}

/** Belt-test score badge (PHP score_badge / badge_result). */
export function ScoreBadge({ result, score }: { result: string; score: number | null }) {
  if (score === null) return <span className="badge bg-secondary">Pending</span>;
  const cls = result === 'pass' ? 'bg-success' : result === 'fail' ? 'bg-danger' : 'bg-secondary';
  return <span className={`badge ${cls}`}>{score}%</span>;
}

/** Sub-page heading with a back link to the student's dashboard tab. */
export function SubPageHeading({ studentId, title, name }: { studentId: number; title: string; name: string }) {
  return (
    <div className="d-flex align-items-center gap-3 mb-4 flex-wrap">
      <Link to={`/student/${studentId}`} className="btn btn-sm btn-outline-secondary">← Dashboard</Link>
      <h4 className="mb-0">{title} — {personName(name)}</h4>
    </div>
  );
}

/** Loading spinner / error alert wrapper for page-level fetches. */
export function PageState({ error, loading }: { error: string | null; loading: boolean }) {
  if (error) return <div className="alert alert-danger">{error}</div>;
  if (loading) {
    return (
      <div className="text-center p-5">
        <div className="spinner-border text-primary" role="status" aria-label="Loading" />
      </div>
    );
  }
  return null;
}

/** Family tab bar (own student + children), highlighting the active tab. */
export function FamilyTabs({ activeId }: { activeId: number }) {
  const { family } = useSession();
  const tabs = [
    ...(family.own_student ? [family.own_student] : []),
    ...family.children,
  ];
  if (tabs.length === 0) return null;
  return (
    <ul className="nav nav-tabs mb-4">
      {tabs.map((s) => (
        <li className="nav-item" key={s.id}>
          <Link
            className={`nav-link ${s.id === activeId ? 'active' : ''}`}
            to={`/student/${s.id}`}
          >
            {personName(`${s.first_name} ${s.last_name}`)}
          </Link>
        </li>
      ))}
    </ul>
  );
}
