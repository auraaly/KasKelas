<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ===== DARK MODE =====
(function() {
    const saved = localStorage.getItem('darkMode');
    if (saved === '1') document.documentElement.setAttribute('data-theme', 'dark');
})();

function toggleDarkMode() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    if (isDark) {
        document.documentElement.removeAttribute('data-theme');
        localStorage.setItem('darkMode', '0');
    } else {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('darkMode', '1');
    }
    updateDarkIcon();
}

function updateDarkIcon() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const btn = document.getElementById('darkModeBtn');
    if (btn) btn.innerHTML = isDark
        ? '<i class="bi bi-sun-fill"></i>'
        : '<i class="bi bi-moon-fill"></i>';
}

document.addEventListener('DOMContentLoaded', updateDarkIcon);

// ===== TOOLTIPS =====
document.addEventListener('DOMContentLoaded', function() {
    const tooltips = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltips.forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
});

// ===== RIPPLE EFFECT =====
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-premium, .btn-gradient-success, .btn-gradient-danger, .btn');
    if (!btn) return;
    const ripple = document.createElement('span');
    const rect = btn.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height);
    ripple.style.cssText = `
        position:absolute;width:${size}px;height:${size}px;
        left:${e.clientX - rect.left - size/2}px;
        top:${e.clientY - rect.top - size/2}px;
        background:rgba(255,255,255,0.3);border-radius:50%;
        transform:scale(0);animation:rippleAnim 0.5s ease-out forwards;
        pointer-events:none;
    `;
    if (getComputedStyle(btn).position === 'static') btn.style.position = 'relative';
    btn.style.overflow = 'hidden';
    btn.appendChild(ripple);
    setTimeout(() => ripple.remove(), 500);
});
</script>

<style>
@keyframes rippleAnim {
    to { transform: scale(2.5); opacity: 0; }
}

/* ===== DARK MODE ===== */
[data-theme="dark"] {
    --bg: #0f172a;
    --surface: #1e293b;
    --border: #334155;
    --text: #f1f5f9;
    --text-muted: #94a3b8;
}
[data-theme="dark"] body { background: #0f172a; color: #f1f5f9; }
[data-theme="dark"] .table-box,
[data-theme="dark"] .table-panel,
[data-theme="dark"] .form-panel,
[data-theme="dark"] .filter-panel,
[data-theme="dark"] .glass-card { background: #1e293b !important; border-color: #334155 !important; color: #f1f5f9; }
[data-theme="dark"] .content,
[data-theme="dark"] .page-shell,
[data-theme="dark"] .container-fluid,
[data-theme="dark"] .table-box *,
[data-theme="dark"] .table-panel *,
[data-theme="dark"] .form-panel *,
[data-theme="dark"] .filter-panel *,
[data-theme="dark"] .glass-card * { color: inherit; }
[data-theme="dark"] h1,
[data-theme="dark"] h2,
[data-theme="dark"] h3,
[data-theme="dark"] h4,
[data-theme="dark"] h5,
[data-theme="dark"] h6,
[data-theme="dark"] label,
[data-theme="dark"] .form-label,
[data-theme="dark"] .table-panel-title,
[data-theme="dark"] .table-panel-subtitle,
[data-theme="dark"] .form-panel-title,
[data-theme="dark"] .form-panel-subtitle,
[data-theme="dark"] .card-title,
[data-theme="dark"] .card-value,
[data-theme="dark"] .balance-pill-label,
[data-theme="dark"] .empty-state,
[data-theme="dark"] .small,
[data-theme="dark"] small,
[data-theme="dark"] p,
[data-theme="dark"] span,
[data-theme="dark"] li,
[data-theme="dark"] td,
[data-theme="dark"] th,
[data-theme="dark"] a:not(.btn):not(.btn-premium):not(.btn-gradient-success):not(.btn-gradient-danger) { color: #e2e8f0 !important; }
[data-theme="dark"] .table { color: #f1f5f9; }
[data-theme="dark"] .table thead th { background: #0f172a !important; color: #94a3b8 !important; border-color: #334155 !important; }
[data-theme="dark"] .table tbody td { border-color: #1e293b !important; }
[data-theme="dark"] .table tbody tr:hover { background: #334155 !important; }
[data-theme="dark"] .form-soft-control { background: #0f172a !important; border-color: #334155 !important; color: #f1f5f9 !important; }
[data-theme="dark"] .form-soft-control:focus { background: #1e293b !important; }
[data-theme="dark"] .form-soft-control::placeholder,
[data-theme="dark"] textarea::placeholder { color: #94a3b8 !important; }
[data-theme="dark"] select.form-soft-control option { color: #f1f5f9; background: #0f172a; }
[data-theme="dark"] .text-muted, [data-theme="dark"] .text-dark { color: #94a3b8 !important; }
[data-theme="dark"] .fw-semibold, [data-theme="dark"] .fw-bold { color: #f1f5f9; }
[data-theme="dark"] .table-light { background: #0f172a !important; }
[data-theme="dark"] .alert-info { background: #1e3a5f; border-color: #2563eb; color: #93c5fd; }
[data-theme="dark"] .alert-success { background: #052e16; border-color: #166534; color: #bbf7d0; }
[data-theme="dark"] .alert-danger { background: #450a0a; border-color: #991b1b; color: #fecaca; }
[data-theme="dark"] .modal-content { background: #1e293b !important; color: #f1f5f9; }
[data-theme="dark"] .modal-header, [data-theme="dark"] .modal-footer { border-color: #334155 !important; }
[data-theme="dark"] .input-group-text { background: #0f172a; border-color: #334155; color: #94a3b8; }
[data-theme="dark"] .btn-light { background: #334155; color: #f1f5f9; border-color: #475569; }
[data-theme="dark"] .badge.bg-light { background: #334155 !important; color: #f1f5f9 !important; }
[data-theme="dark"] .summary-chip { background: #334155; color: #cbd5e1; border-color: #475569; }
[data-theme="dark"] .status-badge-soft.success { background: #14532d; color: #bbf7d0; }
[data-theme="dark"] .status-badge-soft.warning { background: #78350f; color: #fde68a; }
[data-theme="dark"] .status-badge-soft.danger { background: #7f1d1d; color: #fecaca; }
[data-theme="dark"] .page-title-gradient-primary,
[data-theme="dark"] .page-title-gradient-success,
[data-theme="dark"] .page-title-gradient-danger {
    -webkit-text-fill-color: initial;
    background: none;
    color: #f8fafc !important;
}
[data-theme="dark"] .page-hero-copy p { color: #94a3b8; }
[data-theme="dark"] .progress { background: #334155 !important; }
</style>

</body>
</html>
