
</div><!-- /container -->

<footer id="site-footer">
    <div class="container py-0" style="max-width:500px">
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
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function() {
    var html  = document.getElementById('html-root');
    var btn   = document.getElementById('dark-toggle');
    var knob  = document.getElementById('dark-knob');

    function applyTheme(dark) {
        if (dark) {
            html.setAttribute('data-bs-theme', 'dark');
            btn.style.background = '#ccc';
            knob.style.transform = 'translateX(20px)';
        } else {
            html.removeAttribute('data-bs-theme');
            btn.style.background = '#ccc';
            knob.style.transform = 'translateX(0)';
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

// Unsaved-changes guard — warns before leaving any page with a dirty form
(function() {
    var dirty = false;
    window.setFormClean = function() { dirty = false; };
    document.querySelectorAll('form input, form textarea, form select').forEach(function(el) {
        el.addEventListener('change', function() { dirty = true; });
        el.addEventListener('input',  function() { dirty = true; });
    });
    document.querySelectorAll('form').forEach(function(f) {
        f.addEventListener('submit', function() { dirty = false; });
    });
    window.addEventListener('beforeunload', function(e) {
        if (dirty) {
            e.preventDefault();
            return (e.returnValue = '');
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

