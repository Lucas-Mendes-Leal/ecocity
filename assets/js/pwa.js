/**
 * Registro do Service Worker (PWA básico do EcoCity).
 */
export function iniciarPwa() {
    if (!('serviceWorker' in navigator)) return;
    const registrar = () => {
        navigator.serviceWorker.register('./sw.js', { scope: './' }).catch(() => {});
    };
    if (document.readyState === 'complete') registrar();
    else window.addEventListener('load', registrar, { once: true });
}

iniciarPwa();
