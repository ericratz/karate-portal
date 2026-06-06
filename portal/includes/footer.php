
</div><!-- /container -->

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
</script>
</body>
</html>
