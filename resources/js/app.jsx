import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const key = import.meta.env.VITE_REVERB_APP_KEY;
if (key) {
    window.Pusher = Pusher;
    const host = String(import.meta.env.VITE_REVERB_HOST || 'localhost').replaceAll('"', '');
    const port = Number(import.meta.env.VITE_REVERB_PORT || 8080);
    window.Echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: host,
        wsPort: port,
        wssPort: port,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME || 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} · Eldritch Runes` : 'Eldritch Runes'),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx', { eager: true });
        return pages[`./Pages/${name}.jsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#8f3140' },
});

router.on('invalid', () => {});
