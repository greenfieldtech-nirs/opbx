/* ESLint 8 (legacy config). Baseline: real bugs are errors; legacy stylistic noise is off. */
module.exports = {
  root: true,
  env: { browser: true, es2022: true },
  parser: '@typescript-eslint/parser',
  parserOptions: { ecmaVersion: 'latest', sourceType: 'module', ecmaFeatures: { jsx: true } },
  plugins: ['@typescript-eslint', 'react-hooks', 'react-refresh'],
  extends: ['eslint:recommended', 'plugin:@typescript-eslint/recommended', 'plugin:react-hooks/recommended'],
  ignorePatterns: ['dist', 'dist-embed', 'node_modules', 'public', '*.cjs', 'vite*.config.ts', 'vitest.config.ts'],
  rules: {
    // rules-of-hooks stays an error (from recommended); exhaustive-deps is advisory legacy noise
    'react-hooks/exhaustive-deps': 'off',
    'react-refresh/only-export-components': 'off',
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
}
