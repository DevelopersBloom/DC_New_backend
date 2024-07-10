import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    extends: [
        // add more generic rulesets here, such as:
        // 'eslint:recommended',
        // "prettier"
        // 'plugin:vue/recommended' // Use this if you are using Vue.js 2.x.
    ],
    plugins: [
        laravel([
            'resources/css/app.css',
            'resources/js/app.js',
        ]),
    ],
});
