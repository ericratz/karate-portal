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

/** The admin "Admin" dropdown — same items/sections as includes/header.php. */
function AdminMenu({ onNavigate }: { onNavigate: () => void }) {
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

  const spa = (to: string, label: string) => (
    <li>
      <Link className="dropdown-item" to={to} onClick={() => { setOpen(false); onNavigate(); }}>
        {label}
      </Link>
    </li>
  );

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
        Admin
      </a>
      <ul
        className={`dropdown-menu dropdown-menu-end${open ? ' show' : ''}`}
        style={{ maxHeight: 'calc(100vh - 120px)', overflowY: 'auto', right: 0 }}
      >
        <li><h6 className="dropdown-header">Student Info</h6></li>
        {spa('/instructor/attendance', 'Attendance')}
        {spa('/instructor/belt-tests', 'Belt Tests')}
        {spa('/admin/notes', 'Class Notes')}
        {spa('/admin/email', 'Email Students')}
        {spa('/instructor', 'Instructor Dashboard')}
        {spa('/admin/roster', 'Roster')}
        <li><hr className="dropdown-divider" /></li>
        <li><h6 className="dropdown-header">Finances</h6></li>
        {spa('/admin/donations', 'Donations')}
        {spa('/admin/waivers', 'Exemptions')}
        {spa('/admin/expenses', 'Expenses')}
        {spa('/admin/payments', 'Payments')}
        <li><hr className="dropdown-divider" /></li>
        <li><h6 className="dropdown-header">Security</h6></li>
        {spa('/admin/logs', 'Logs')}
        {spa('/admin/users', 'Users')}
      </ul>
    </li>
  );
}

export default function Layout({ children }: { children: ReactNode }) {
  const { me } = useSession();
  const tip = roleTips[me.role];
  const isAdmin = me.role === 'admin';
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
          {isAdmin && (
            <button
              className="navbar-toggler"
              type="button"
              aria-label="Toggle navigation menu"
              onClick={() => setNavOpen((v) => !v)}
            >
              <span className="navbar-toggler-icon" />
            </button>
          )}
          <div className={isAdmin ? `collapse navbar-collapse${navOpen ? ' show' : ''}` : 'd-flex ms-auto'} id="navMenu">
            {isAdmin && (
              <ul className="navbar-nav me-auto">
                <li className="nav-item">
                  <Link className="nav-link nav-link-lg" to="/admin/roster" onClick={() => setNavOpen(false)}>
                    Roster
                  </Link>
                </li>
                <li className="nav-item">
                  <Link className="nav-link nav-link-lg" to="/instructor/attendance" onClick={() => setNavOpen(false)}>
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
                <AdminMenu onNavigate={() => setNavOpen(false)} />
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
