// ESLint flat config. Baseline: real bugs are errors; legacy stylistic noise is off.
import js from '@eslint/js';
import { defineConfig } from 'eslint/config';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

export default defineConfig(
  {
    ignores: ['dist', 'dist-embed', 'node_modules', 'public', '**/*.{js,mjs,cjs}', 'vite*.config.ts', 'vitest.config.ts'],
  },
  {
    files: ['**/*.{ts,tsx}'],
    extends: [js.configs.recommended, tseslint.configs.recommended],
    languageOptions: { globals: globals.browser },
    plugins: { 'react-hooks': reactHooks },
    rules: {
      // Only rules-of-hooks: react-hooks v7's recommended preset adds React Compiler rules this code
      // was not written for; exhaustive-deps stays off as advisory legacy noise.
      'react-hooks/rules-of-hooks': 'error',
      '@typescript-eslint/no-explicit-any': 'off',
      '@typescript-eslint/no-unused-vars': 'off',
      '@typescript-eslint/no-empty-object-type': 'off',
      '@typescript-eslint/no-unused-expressions': 'off',
      '@typescript-eslint/no-require-imports': 'off',
      'no-empty': 'off',
      'no-useless-escape': 'off',
      'no-case-declarations': 'off',
      'no-prototype-builtins': 'off',
    },
  },
);
