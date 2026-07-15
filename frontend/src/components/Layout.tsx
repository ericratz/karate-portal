// Navbar + page container — the React counterpart of includes/header.php's
// parent-role chrome: purple navbar, dark-mode toggle (same localStorage key
// the PHP pages use, so the preference follows the user across both), user
// badge, and logout.

import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useSession } from '../SessionContext';

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

export default function Layout({ children }: { children: ReactNode }) {
  const { me } = useSession();
  const tip = roleTips[me.role];

  return (
    <>
      <nav className="navbar navbar-expand-md sticky-top">
        <div className="container-fluid">
          <Link className="navbar-brand fw-semibold" to="/">
            My Dashboard
          </Link>
          <div className="d-flex align-items-center gap-3 ms-auto">
            <DarkToggle />
            <span className="navbar-text">
              {me.username}
              <span className="role-badge" title={tip}>{me.role}</span>
              &nbsp;
              <a href="../logout.php" className="btn btn-sm btn-logout ms-2">Log out</a>
            </span>
          </div>
        </div>
      </nav>
      <div className="container-fluid py-4 px-4">{children}</div>
    </>
  );
}
