document.addEventListener('DOMContentLoaded', () => {
    const rootStyles = getComputedStyle(document.documentElement);

    if (window.Chart) {
        Chart.defaults.font.family = '"Public Sans", "Segoe UI", sans-serif';
        Chart.defaults.color = rootStyles.getPropertyValue('--muted').trim() || '#53627a';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
    }

    const searchInput = document.querySelector('[data-facility-search]');
    const rows = document.querySelectorAll('[data-facility-row]');

    if (searchInput && rows.length) {
        searchInput.addEventListener('input', () => {
            const term = searchInput.value.trim().toLowerCase();
            rows.forEach((row) => {
                const haystack = row.getAttribute('data-search') || '';
                row.style.display = haystack.includes(term) ? '' : 'none';
            });
        });
    }
});
