
</main><!-- end .max-w-7xl -->

<script>
/**
 * Toast notification helper
 * Usage: showToast('Form saved!', 'success');
 *        showToast('Something went wrong', 'error');
 */
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    const colors = {
        success: 'bg-emerald-600 text-white',
        error:   'bg-red-600 text-white',
        info:    'bg-brand-600 text-white',
    };
    toast.className = `toast px-5 py-3 rounded-lg shadow-lg text-sm font-medium ${colors[type] || colors.info}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 3200);
}

/**
 * Generic AJAX helper.
 * Returns parsed JSON or throws.
 */
async function api(url, data) {
    const resp = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(data),
    });
    const json = await resp.json();
    if (!resp.ok || json.error) throw new Error(json.error || 'Request failed');
    return json;
}

/**
 * Confirm dialog helper
 */
function confirmAction(message) {
    return confirm(message);
}
</script>

</body>
</html>
