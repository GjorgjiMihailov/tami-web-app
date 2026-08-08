import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Manrope', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    DEFAULT: '#ff6600',
                    light: '#ff8533',
                    dark: '#cc5200',
                },
                canvas: {
                    DEFAULT: '#FFF8F3',
                },
            },
            boxShadow: {
                card: '0 1px 3px 0 rgba(15, 23, 42, 0.06)',
                'card-hover': '0 10px 24px -6px rgba(255, 102, 0, 0.22)',
            },
        },
    },

    plugins: [forms],
};
