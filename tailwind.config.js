/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'class',
    content: [
        "./node_modules/flowbite-vue/**/*.{js,jsx,ts,tsx}",
        "./node_modules/flowbite/**/*.js",
        "./resources/**/*.blade.php",
        "./resources/**/*.js",
        "./resources/**/*.vue",
    ],
    theme: {
        extend: {
            fontSize: {
                xxs: '0.6rem',
            }
        },

    },
    plugins: [require('flowbite/plugin')],
}
