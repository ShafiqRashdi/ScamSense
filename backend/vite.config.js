import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.scss', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Bootstrap 5.3 still uses `@import` and the legacy `red()`/`green()`/
                // `blue()` colour functions, both deprecated in Dart Sass 1.80+. The
                // warnings come from inside node_modules and there is nothing to fix on
                // our side, so they are silenced to keep a real build failure visible.
                // Revisit when Bootstrap ships its `@use` migration.
                silenceDeprecations: [
                    'import',
                    'color-functions',
                    'global-builtin',
                    'mixed-decls',
                ],
            },
        },
    },
});
