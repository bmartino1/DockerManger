(() => {
    const search = document.getElementById('stack-search');
    if (!search) return;

    search.addEventListener('input', () => {
        const needle = search.value.trim().toLowerCase();
        document.querySelectorAll('.stack-item').forEach((item) => {
            item.hidden = !item.dataset.name.includes(needle);
        });
    });
})();
