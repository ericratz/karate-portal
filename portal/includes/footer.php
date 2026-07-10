
</div><!-- /container -->

<footer id="site-footer">
    <button id="footerCollapseBtn" title="Contact Noji"
            style="position:absolute;top:0;right:16px;transform:translateY(-100%);
                   background:#6f42c1;border:1px solid rgba(255,255,255,.35);border-bottom:none;
                   border-radius:6px 6px 0 0;padding:3px 11px;cursor:pointer;
                   color:#fff;line-height:1;display:flex;align-items:center">
        <svg id="footer-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14"
             fill="currentColor" viewBox="0 0 16 16" style="transition:transform .25s">
            <path fill-rule="evenodd" d="M7.646 4.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1-.708.708L8 5.707l-5.646 5.647a.5.5 0 0 1-.708-.708z"/>
        </svg>
    </button>
    <div style="display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;padding:0 12px">
        <!-- Trademark -->
        <div class="d-none d-md-block"
             style="color:#fff;font-size:.86rem;line-height:1.5;min-width:0;overflow-wrap:break-word">
            All trademarks and registered trademarks cited herein are the property of their respective owners.
        </div>
        <!-- Questions card -->
        <div style="flex-shrink:0;width:clamp(220px,40vw,500px)">
            <div class="card border-0 shadow-sm mb-0">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">Questions or Issues?</span>
                    <button class="btn btn-sm btn-warning" type="button"
                            data-bs-toggle="collapse" data-bs-target="#footerFeedback">
                        Contact Noji
                    </button>
                </div>
                <div class="collapse" id="footerFeedback">
                    <div class="card-body">
                        <p class="text-muted small mb-3">Have a question or running into an issue? Send Noji a message below.</p>
                        <div id="footerFeedbackSuccess" class="alert alert-success d-none mb-0">Message sent! Noji will get back to you soon.</div>
                        <div id="footerFeedbackError" class="alert alert-danger d-none"></div>
                        <form id="footerFeedbackForm">
                            <div class="mb-3">
                                <textarea name="feedback_message" id="footerFeedbackMsg" class="form-control" rows="4"
                                          placeholder="Type your message here…" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary" id="footerFeedbackBtn">Send Message</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <!-- Copyright -->
        <div class="d-none d-md-block text-end"
             style="color:#fff;font-size:.86rem;line-height:1.5;min-width:0;overflow-wrap:break-word">
            © 2026 Ratzlaff Family
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz"
        crossorigin="anonymous"></script>
<script nonce="<?= csp_nonce() ?>">
(function() {
    var html  = document.getElementById('html-root');
    var btn   = document.getElementById('dark-toggle');
    var knob  = document.getElementById('dark-knob');
    var label = document.getElementById('dark-label');

    function applyTheme(dark) {
        if (dark) {
            html.setAttribute('data-bs-theme', 'dark');
            btn.style.background  = '#b57cec';
            btn.style.borderColor = '#888';
            label.style.color     = '#3d1a7a';
            label.textContent     = 'Light';
            knob.style.background = '#212529';
            knob.style.transform  = 'translateX(50px)';
        } else {
            html.removeAttribute('data-bs-theme');
            btn.style.background  = '#3d1a7a';
            btn.style.borderColor = '#888';
            label.style.color     = '#e0e0e0';
            label.textContent     = 'Dark';
            knob.style.background = '#fff';
            knob.style.transform  = 'translateX(0)';
        }
    }

    applyTheme(localStorage.getItem('theme') === 'dark');

    btn.addEventListener('click', function() {
        var dark = html.getAttribute('data-bs-theme') !== 'dark';
        localStorage.setItem('theme', dark ? 'dark' : 'light');
        applyTheme(dark);
    });
})();

// Re-init Bootstrap tooltips after HTMX partial swaps
document.addEventListener('htmx:afterSettle', function(e) {
    e.detail.elt.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
});

// Reload on back-navigation to prevent stale bfcache data
window.addEventListener('pageshow', function(e) {
    if (e.persisted) window.location.reload();
});

// Unsaved-changes guard — warns before leaving any page with a dirty form.
// Scoped to POST forms only — GET filter/search forms aren't data entry and
// shouldn't trip this (e.g. changing a filter dropdown isn't "unsaved work").
(function() {
    var dirty = false;
    window.setFormClean = function() { dirty = false; };
    document.querySelectorAll('form:not([method="get"]) input, form:not([method="get"]) textarea, form:not([method="get"]) select').forEach(function(el) {
        el.addEventListener('change', function() { dirty = true; });
        el.addEventListener('input',  function() { dirty = true; });
    });
    document.querySelectorAll('form:not([method="get"])').forEach(function(f) {
        f.addEventListener('submit', function() { dirty = false; });
    });
    window.addEventListener('beforeunload', function(e) {
        if (dirty) {
            e.preventDefault();
            return (e.returnValue = '');
        }
    });
})();

// Footer collapse — arrow tab + click-outside
(function() {
    var collapseEl = document.getElementById('footerFeedback');
    var chevron    = document.getElementById('footer-chevron');
    var tabBtn     = document.getElementById('footerCollapseBtn');
    var footer     = document.getElementById('site-footer');
    if (!collapseEl || !chevron || !tabBtn) return;

    var bsCol = bootstrap.Collapse.getOrCreateInstance(collapseEl, { toggle: false });

    collapseEl.addEventListener('show.bs.collapse', function() {
        chevron.style.transform = 'rotate(180deg)';
    });
    collapseEl.addEventListener('hide.bs.collapse', function() {
        chevron.style.transform = 'rotate(0deg)';
    });

    tabBtn.addEventListener('click', function() { bsCol.toggle(); });

    document.addEventListener('click', function(e) {
        if (!footer.contains(e.target) && collapseEl.classList.contains('show')) {
            bsCol.hide();
        }
    });
})();

// Footer feedback form — AJAX submit
(function() {
    var form    = document.getElementById('footerFeedbackForm');
    var success = document.getElementById('footerFeedbackSuccess');
    var error   = document.getElementById('footerFeedbackError');
    var btn     = document.getElementById('footerFeedbackBtn');
    var msg     = document.getElementById('footerFeedbackMsg');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        btn.disabled = true;
        success.classList.add('d-none');
        error.classList.add('d-none');
        var data = new FormData();
        data.append('feedback_message', msg.value);
        data.append('csrf_token', '<?= csrf_token() ?>');
        fetch('<?= SITE_URL ?>/api/send_feedback.php', {
            method: 'POST',
            body: data
        })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.ok) {
                form.classList.add('d-none');
                success.classList.remove('d-none');
            } else {
                error.textContent = j.error || 'Something went wrong.';
                error.classList.remove('d-none');
                btn.disabled = false;
            }
        })
        .catch(function() {
            error.textContent = 'Something went wrong. Please try again.';
            error.classList.remove('d-none');
            btn.disabled = false;
        });
    });
})();
</script>
</body>
</html>

