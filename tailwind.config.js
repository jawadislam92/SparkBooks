import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
    ],

    theme: {
        extend: {
            colors: {
                ink: {
                    DEFAULT: '#16323B',
                    100: '#D7E2E1',
                    600: '#2E6273',
                    700: '#24505E',
                    800: '#1D3E49',
                },
                paper: {
                    DEFAULT: '#F7FAF7',
                    raised: '#FFFFFF',
                },
                rule: '#E4EEE6',
                spark: {
                    DEFAULT: '#0FA678',
                    50: '#EAF7F1',
                    600: '#0C8A64',
                },
                lagoon: '#0C8FA6',
                amber: {
                    DEFAULT: '#E8A13D',
                    50: '#FBF3E4',
                },
                clay: {
                    DEFAULT: '#D65A4A',
                    50: '#F9ECEA',
                },
            },
            fontFamily: {
                display: ['"Bricolage Grotesque"', 'sans-serif'],
                sans: ['"Public Sans"', 'sans-serif'],
                mono: ['"Spline Sans Mono"', 'monospace'],
            },
            boxShadow: {
                sheet: '0 1px 2px rgba(22,50,59,.06), 0 4px 16px rgba(22,50,59,.06)',
            },
            borderRadius: {
                sheet: '0.875rem',
            },
        },
    },

    plugins: [forms],
};
