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
                // Само за јавната страна (resources/views/marketing). Внатре во
                // апликацијата фонтот останува Manrope преку `font-sans`.
                display: ['Fraunces', ...defaultTheme.fontFamily.serif],
                body: ['Inter', ...defaultTheme.fontFamily.sans],
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
                // Преземено збор за збор од tailwind.config.js на financebuddy.mk,
                // за да бидат јавната страница и порталот едно семејство. Таму
                // `sand` се вика `border`; преименувано е само за да не се меша
                // со Tailwind-овата `border-*` помошна класа.
                ink: '#1C1A17',
                paper: '#FBF8F3',
                'paper-warm': '#F2ECE1',
                forest: '#14532D',
                stone: '#6B6358',
                sand: '#E5DDD0',
            },
            boxShadow: {
                card: '0 1px 3px 0 rgba(15, 23, 42, 0.06)',
            },
        },
    },

    plugins: [forms],
};
