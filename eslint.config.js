import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';

export default tseslint.config(
    {
        ignores: ['vendor/**', 'node_modules/**', 'public/build/**', 'storage/**', 'bootstrap/**', 'e2e/playwright-report/**', 'e2e/test-results/**'],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    reactHooks.configs.flat['recommended-latest'],
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
        },
    },
);
