import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        // Serverul de dezvoltare rulează în containerul `vite` (docker-compose.yml).
        // Implicit, Vite se leagă pe localhost — adică pe loopback-ul CONTAINERULUI,
        // deci portul publicat 5173 nu ar duce nicăieri. `hmr.host` rămâne `localhost`
        // pentru că e adresa din perspectiva BROWSERULUI: cu `0.0.0.0` și fără el,
        // laravel-vite-plugin scrie `http://0.0.0.0:5173` în fișierul `hot`.
        host: '0.0.0.0',
        hmr: {
            host: 'localhost',
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
