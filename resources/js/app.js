const catalogPage = document.querySelector('[data-catalog-page]');

if (catalogPage) {
    document.querySelector('[data-catalog-filters]')?.addEventListener('change', (event) => {
        if (event.target.matches('input[type="date"], select')) {
            event.currentTarget.requestSubmit();
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            window.location.reload();
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });
}
