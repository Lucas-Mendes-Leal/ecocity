/**
 * Página offline do PWA: tenta recarregar automaticamente quando a conexão volta.
 */
window.addEventListener('online', () => {
    window.location.reload();
});

document.getElementById('offline-retry')?.addEventListener('click', (e) => {
    e.preventDefault();
    window.location.reload();
});
