// Navbar + page container — the React counterpart of includes/header.php's
// chrome: purple navbar, dark-mode toggle (same localStorage key the PHP
// pages use, so the preference follows the user across both), user badge,
// and logout. Admins additionally get the header's nav links and the "Admin"
// dropdown (React-controlled — no bootstrap JS in the bundle), mixing SPA
// routes for migrated pages with plain hrefs for the still-PHP ones.

import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useSession } from '../SessionContext';
import { ExtIcon } from './shared';
import Footer from './Footer';

const roleTips: Record<string, string> = {
  student: 'Registration fee paid',
  parent: 'Family account',
  guest: 'Non-paying participant (registration fee not yet paid)',
};

function applyTheme(dark: boolean) {
  document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
}

function DarkToggle() {
  const [dark, setDark] = useState(() => localStorage.getItem('theme') === 'dark');

  useEffect(() => {
    applyTheme(dark);
  }, [dark]);

  return (
    <button
      type="button"
      className={`dark-toggle ${dark ? 'on' : ''}`}
      title="Toggle dark mode"
      aria-label="Toggle dark mode"
      onClick={() => {
        const next = !dark;
        localStorage.setItem('theme', next ? 'dark' : 'light');
        setDark(next);
      }}
    >
      <span className="dark-label">{dark ? 'Light' : 'Dark'}</span>
      <span className="dark-knob" />
    </button>
  );
}

/**
 * One entry in a navbar dropdown: an SPA route, a plain href (for the pages
 * still served by PHP), a section heading, or a rule.
 */
type MenuEntry =
  | { to: string; label: string }
  | { href: string; label: string }
  | { header: string }
  | { divider: true };

/**
 * Navbar dropdown shell — React-controlled, so no bootstrap JS in the bundle.
 * Shared by the Admin and Instructor menus rather than duplicated: the two
 * differ only in their label and their entries.
 */
function NavMenu({
  label,
  entries,
  onNavigate,
}: {
  label: string;
  entries: MenuEntry[];
  onNavigate: () => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLLIElement>(null);

  useEffect(() => {
    if (!open) return;
    const close = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('click', close);
    return () => document.removeEventListener('click', close);
  }, [open]);

  return (
    <li className="nav-item dropdown" ref={ref}>
      <a
        className="nav-link dropdown-toggle"
        href="#"
        role="button"
        onClick={(e) => {
          e.preventDefault();
          setOpen((v) => !v);
        }}
      >
        {label}
      </a>
      <ul
        className={`dropdown-menu dropdown-menu-end${open ? ' show' : ''}`}
        style={{ maxHeight: 'calc(100vh - 120px)', overflowY: 'auto', right: 0 }}
      >
        {entries.map((entry, i) => {
          if ('divider' in entry) return <li key={i}><hr className="dropdown-divider" /></li>;
          if ('header' in entry) return <li key={i}><h6 className="dropdown-header">{entry.header}</h6></li>;
          if ('href' in entry) {
            return (
              <li key={i}>
                <a className="dropdown-item" href={entry.href}>{entry.label}</a>
              </li>
            );
          }
          return (
            <li key={i}>
              <Link
                className="dropdown-item"
                to={entry.to}
                onClick={() => { setOpen(false); onNavigate(); }}
              >
                {entry.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </li>
  );
}

/** The admin "Admin" dropdown — same items/sections as includes/header.php. */
const ADMIN_ENTRIES: MenuEntry[] = [
  { header: 'Student Info' },
  { to: '/instructor/classes', label: 'Attendance' },
  { to: '/instructor/belt-tests', label: 'Belt Tests' },
  { to: '/admin/notes', label: 'Class Notes' },
  { to: '/admin/email', label: 'Email Students' },
  { to: '/instructor', label: 'Instructor Dashboard' },
  { to: '/admin/roster', label: 'Roster' },
  { divider: true },
  { header: 'Finances' },
  { to: '/admin/donations', label: 'Donations' },
  { to: '/admin/waivers', label: 'Exemptions' },
  { to: '/admin/expenses', label: 'Expenses' },
  { to: '/admin/payments', label: 'Payments' },
  { divider: true },
  { header: 'Security' },
  { to: '/admin/logs', label: 'Logs' },
  { to: '/admin/users', label: 'Users' },
  // Server-rendered, so a plain href rather than an SPA route.
  { href: '/karate/portal/admin/security.php', label: 'Two-Factor' },
];

/**
 * The instructor "Menu" dropdown. Instructors previously had no navbar links at
 * all — only the dark-mode toggle and Log out — so every move had to go back
 * through a dashboard card. These are the instructor-reachable routes.
 */
const INSTRUCTOR_ENTRIES: MenuEntry[] = [
  { to: '/instructor', label: 'Instructor Dashboard' },
  { to: '/instructor/roster', label: 'Roster' },
  { divider: true },
  { header: 'Attendance' },
  { to: '/instructor/classes', label: 'Classes' },
  { to: '/instructor/attendance', label: 'Take Attendance' },
  { divider: true },
  { to: '/instructor/belt-tests', label: 'Belt Tests' },
];

export default function Layout({ children }: { children: ReactNode }) {
  const { me } = useSession();
  const tip = roleTips[me.role];
  const isAdmin = me.role === 'admin';
  const isInstructor = me.role === 'instructor';
  // Both roles get a collapsible navbar; everyone else keeps the bare chrome.
  const hasNav = isAdmin || isInstructor;
  const [navOpen, setNavOpen] = useState(false);

  return (
    <>
      <nav className="navbar navbar-expand-md sticky-top">
        <div className="container-fluid">
          {isAdmin ? (
            <Link className="navbar-brand fw-semibold" to="/admin">Admin Dashboard</Link>
          ) : (
            <Link
              className="navbar-brand fw-semibold"
              to={me.role === 'instructor' ? '/instructor' : '/'}
            >
              My Dashboard
            </Link>
          )}
          {hasNav && (
            <button
              className="navbar-toggler"
              type="button"
              aria-label="Toggle navigation menu"
              onClick={() => setNavOpen((v) => !v)}
            >
              <span className="navbar-toggler-icon" />
            </button>
          )}
          <div className={hasNav ? `collapse navbar-collapse${navOpen ? ' show' : ''}` : 'd-flex ms-auto'} id="navMenu">
            {isInstructor && (
              <ul className="navbar-nav me-auto">
                <li className="nav-item">
                  <Link className="nav-link nav-link-lg" to="/instructor/roster" onClick={() => setNavOpen(false)}>
                    Roster
                  </Link>
                </li>
                <li className="nav-item">
                  <Link className="nav-link nav-link-lg" to="/instructor/classes" onClick={() => setNavOpen(false)}>
                    Attendance
                  </Link>
                </li>
                <NavMenu label="Menu" entries={INSTRUCTOR_ENTRIES} onNavigate={() => setNavOpen(false)} />
              </ul>
            )}
            {isAdmin && (
              <ul className="navbar-nav me-auto">
                <li className="nav-item">
                  <Link className="nav-link nav-link-lg" to="/admin/roster" onClick={() => setNavOpen(false)}>
                    Roster
                  </Link>
                </li>
                <li className="nav-item">
                  <Link className="nav-link nav-link-lg" to="/instructor/classes" onClick={() => setNavOpen(false)}>
                    Attendance
                  </Link>
                </li>
                <li className="nav-item">
                  <a
                    className="nav-link nav-link-lg"
                    href="https://ericratz.atlassian.net/jira/software/projects/SCRUM/boards/1"
                    target="_blank"
                    rel="noreferrer"
                    style={{ color: '#7ab3f5' }}
                  >
                    Jira <ExtIcon size={12} />
                  </a>
                </li>
                <NavMenu label="Admin" entries={ADMIN_ENTRIES} onNavigate={() => setNavOpen(false)} />
              </ul>
            )}
            <div className="d-flex align-items-center gap-3">
              <DarkToggle />
              <span className="navbar-text">
                {me.username}
                <span className="role-badge" title={tip}>{me.role}</span>
                &nbsp;
                <a href="../logout.php" className="btn btn-sm btn-logout ms-2">Log out</a>
              </span>
            </div>
          </div>
        </div>
      </nav>
      <div className="container-fluid py-4 px-4">{children}</div>
      <Footer />
    </>
  );
}
