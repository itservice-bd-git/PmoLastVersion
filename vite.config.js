import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/ceo-report.js'],   // ceo-report: the CEO report's print page, which has no app.js
            refresh: true,
        }),
    ],
});
