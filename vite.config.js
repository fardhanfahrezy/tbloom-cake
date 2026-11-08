import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny, local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                local('Lufga', {
                    src: 'resources/fonts/Lufga',
                    optimizedFallbacks: false,
                }),
                local('Poppins', {
                    src: 'resources/fonts/Poppins',
                    optimizedFallbacks: false,
                }),
                local('Rubik', {
                    variants: [
                        { src: 'resources/fonts/Rubik/Rubik-VariableFont_wght.ttf', weight: '300 900', style: 'normal' },
                        { src: 'resources/fonts/Rubik/Rubik-Italic-VariableFont_wght.ttf', weight: '300 900', style: 'italic' },
                    ],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
