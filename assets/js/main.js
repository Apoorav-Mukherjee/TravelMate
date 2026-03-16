// ─── Global JS for TravelMate ─────────────────────────────────────

// Auto-dismiss toasts
document.addEventListener('DOMContentLoaded', function () {

    // Bootstrap toast auto-dismiss after 4s
    document.querySelectorAll('.toast').forEach(function (toastEl) {
        const toast = new bootstrap.Toast(toastEl, { delay: 4000 });
        toast.show();
    });

    // Confirm delete buttons
    document.querySelectorAll('[data-confirm]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            if (!confirm(this.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // Auto-hide alerts after 5s
    document.querySelectorAll('.alert:not(.alert-permanent)').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity    = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });

    // Image preview before upload
    document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
        input.addEventListener('change', function () {
            const previewId = this.dataset.preview;
            const preview   = document.getElementById(previewId);
            if (preview && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function (e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    // Activate Bootstrap tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    // Activate Bootstrap popovers
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        new bootstrap.Popover(el);
    });

    // Back button
    document.querySelectorAll('[data-action="back"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            history.back();
        });
    });

    // Form double-submit prevention
    document.querySelectorAll('form[data-once]').forEach(function (form) {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('[type="submit"]');
            if (btn) {
                btn.disabled    = true;
                btn.textContent = 'Please wait...';
            }
        });
    });

});

// ─── Format numbers as Indian currency ───────────────────────────
function formatINR(amount) {
    return '₹' + parseFloat(amount).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

// ─── Debounce utility ────────────────────────────────────────────
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}

// ─── AJAX helper ─────────────────────────────────────────────────
async function ajaxPost(url, data) {
    const formData = new FormData();
    for (const [key, value] of Object.entries(data)) {
        formData.append(key, value);
    }
    const res = await fetch(url, { method: 'POST', body: formData });
    return res.json();
}