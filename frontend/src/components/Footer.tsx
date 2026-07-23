// Site footer — React port of includes/footer.php's <footer id="site-footer">.
// The SPA migration never carried this over, so migrated pages had no footer
// while the still-server-rendered pages (waiver, certificate) kept theirs.
//
// A fixed purple bar with trademark / "Questions or Issues?" card / copyright.
// The card is collapsed by default; the chevron tab (or "Contact Noji") toggles
// it, and a click outside closes it. The form posts to the same
// api/send_feedback.php endpoint the PHP footer used — note that endpoint is
// NOT under /api/v1 and expects form-encoded fields with a csrf_token field
// (not the X-CSRF-Token header + JSON envelope the typed client uses), so this
// posts directly with FormData rather than through apiPost.

import { useEffect, useRef, useState } from 'react';
import { useSession } from '../SessionContext';

const FEEDBACK_URL = '/karate/portal/api/send_feedback.php';

export default function Footer() {
  const { me } = useSession();
  const [open, setOpen] = useState(false);
  const [message, setMessage] = useState('');
  const [status, setStatus] = useState<'idle' | 'sending' | 'sent'>('idle');
  const [error, setError] = useState('');
  const footerRef = useRef<HTMLElement>(null);

  // Click outside the footer closes the open card (matches the PHP behavior).
  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (footerRef.current && !footerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  }, [open]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (status === 'sending') return;
    setStatus('sending');
    setError('');
    try {
      const data = new FormData();
      data.append('feedback_message', message);
      data.append('csrf_token', me.csrf_token);
      const res = await fetch(FEEDBACK_URL, { method: 'POST', body: data, credentials: 'same-origin' });
      const json = (await res.json()) as { ok: boolean; error?: string };
      if (json.ok) {
        setStatus('sent');
      } else {
        setError(json.error || 'Something went wrong.');
        setStatus('idle');
      }
    } catch {
      setError('Something went wrong. Please try again.');
      setStatus('idle');
    }
  }

  return (
    <footer id="site-footer" ref={footerRef} className={open ? 'footer-open' : ''}>
      <button
        id="footerCollapseBtn"
        type="button"
        title="Contact Noji"
        aria-label="Show footer and contact form"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <svg
          id="footer-chevron"
          xmlns="http://www.w3.org/2000/svg"
          width="14"
          height="14"
          fill="currentColor"
          viewBox="0 0 16 16"
          style={{ transform: open ? 'rotate(180deg)' : 'rotate(0deg)', transition: 'transform .25s' }}
        >
          <path fillRule="evenodd" d="M7.646 4.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1-.708.708L8 5.707l-5.646 5.647a.5.5 0 0 1-.708-.708z" />
        </svg>
      </button>
      <div className="footer-grid">
        <div className="footer-side-text">
          All trademarks and registered trademarks cited herein are the property of their respective owners.
        </div>
        <div className="footer-card-wrap">
          <div className="card border-0 shadow-sm mb-0">
            <div className="card-header bg-white d-flex justify-content-between align-items-center">
              <span className="fw-semibold">Questions or Issues?</span>
              <button
                className="btn btn-sm btn-warning"
                type="button"
                onClick={() => setOpen((v) => !v)}
              >
                Contact Noji
              </button>
            </div>
            {open && (
              <div className="card-body">
                {status === 'sent' ? (
                  <div className="alert alert-success mb-0">Message sent! Noji will get back to you soon.</div>
                ) : (
                  <>
                    <p className="text-muted small mb-3">Have a question or running into an issue? Send Noji a message below.</p>
                    {error && <div className="alert alert-danger">{error}</div>}
                    <form onSubmit={submit}>
                      <div className="mb-3">
                        <textarea
                          className="form-control"
                          rows={4}
                          placeholder="Type your message here…"
                          required
                          value={message}
                          onChange={(e) => setMessage(e.target.value)}
                        />
                      </div>
                      <button type="submit" className="btn btn-primary" disabled={status === 'sending'}>
                        {status === 'sending' ? 'Sending…' : 'Send Message'}
                      </button>
                    </form>
                  </>
                )}
              </div>
            )}
          </div>
        </div>
        <div className="footer-side-text text-end">© 2026 Ratzlaff Family</div>
      </div>
    </footer>
  );
}
