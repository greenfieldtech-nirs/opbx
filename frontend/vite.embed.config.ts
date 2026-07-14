import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

// Standalone build for the Embedded Dialer widget. Produces a single,
// self-contained IIFE (React bundled in) plus one CSS file, both served from
// the Laravel app under /embed/assets/ and loaded by the iframe document
// (see EmbedDialerController::renderIframe).
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  define: {
    // The SPA reads import.meta.env at runtime; the widget bundle has no env,
    // so pin production mode to strip React dev warnings.
    'process.env.NODE_ENV': JSON.stringify('production'),
  },
  build: {
    outDir: 'dist-embed',
    emptyOutDir: true,
    sourcemap: false,
    cssCodeSplit: false,
    lib: {
      entry: path.resolve(__dirname, 'src/embed/main.tsx'),
      formats: ['iife'],
      name: 'OpbxEmbedWidget',
      fileName: () => 'embed-widget.js',
    },
    rollupOptions: {
      output: {
        // Stable, unhashed names the iframe HTML can reference directly.
        assetFileNames: (info) =>
          info.name?.endsWith('.css') ? 'embed-widget.css' : '[name][extname]',
      },
    },
  },
})
