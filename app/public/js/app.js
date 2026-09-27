(() => {
    'use strict';

    const search = document.getElementById('stack-search');
    const stackItems = Array.from(document.querySelectorAll('.stack-item'));
    const refreshButton = document.getElementById('refresh-dashboard');

    /**
     * Client-side stack filtering.
     *
     * Stack state itself remains server-authoritative; this only changes which
     * already-rendered stack cards are visible.
     */
    if (search) {
        search.addEventListener('input', () => {
            const query = search.value.trim().toLowerCase();

            for (const item of stackItems) {
                const name = item.dataset.stackName || '';
                item.hidden = query !== '' && !name.includes(query);
            }
        });
    }

    /**
     * Dashboard data is intentionally rendered server-side for now.
     *
     * A normal reload gives us a simple, reliable refresh while the control
     * APIs are still being built. Later this button can refresh selected API
     * resources without replacing the page.
     */
    if (refreshButton) {
        refreshButton.addEventListener('click', () => {
            refreshButton.disabled = true;
            refreshButton.textContent = 'Refreshing…';
            window.location.reload();
        });
    }
})();
