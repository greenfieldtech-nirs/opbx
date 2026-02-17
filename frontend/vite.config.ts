import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

// Parse allowed hosts from environment variable (comma-separated)
const parseAllowedHosts = (): string[] => {
  const defaultHosts = ['opbx_frontend', 'localhost', '.localhost']
  const envHosts = process.env.VITE_ALLOWED_HOSTS
  
  if (!envHosts) {
    return defaultHosts
  }
  
  // Split by comma and trim whitespace
  const customHosts = envHosts.split(',').map(host => host.trim()).filter(Boolean)
  
  // Merge with defaults, removing duplicates
  return [...new Set([...defaultHosts, ...customHosts])]
}

// https://vitejs.dev/config/
export default defineConfig({
  base: '/',
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 3000,
    host: '0.0.0.0', // Required for Docker
    allowedHosts: parseAllowedHosts(),
    proxy: {
      '/api': {
        target: process.env.VITE_API_PROXY_TARGET || 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
    rollupOptions: {
      output: {
        manualChunks: {
          'react-vendor': ['react', 'react-dom', 'react-router-dom'],
          'query-vendor': ['@tanstack/react-query'],
          'ui-vendor': [
            '@radix-ui/react-dialog',
            '@radix-ui/react-dropdown-menu',
            '@radix-ui/react-select',
          ],
        },
      },
    },
  },
})
