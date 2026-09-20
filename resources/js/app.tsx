import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
// Import de efect: inițializează i18next O SINGURĂ DATĂ, la bootstrap, înaintea oricărei
// pagini. `lib/i18n.ts` citește `<html lang>` sincron din DOM — trebuie să ruleze aici,
// nu într-un `useEffect` dintr-o componentă, ca prima randare să nu arate deja engleză
// pentru un utilizator FR (ADR-022, „Lot I18N" Val 1).
import '@/lib/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'Throughput';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent<{ default: ResolvedComponent }>(
            `./Pages/${name}.tsx`,
            import.meta.glob<{ default: ResolvedComponent }>('./Pages/**/*.tsx'),
        ).then((module) => module.default),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        // Tokenul de accent, citit din CSS la runtime — respectă tema activă
        // și regula „niciun cod de culoare literal" din urls.md.
        color: 'var(--accent-fill)',
    },
});
