document.addEventListener('DOMContentLoaded', () => {
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
