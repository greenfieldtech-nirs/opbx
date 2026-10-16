import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

// Parse allowed hosts from environment variable (comma-separated)
const parseAllowedHosts = (): string[] => {
  const defaultHosts = ['opbx_frontend', 'localhost', '.localhost', 'opbx.ngrok.io']
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
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': path.resolve(import.meta.dirname, './src'),
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
        // Forward the browser's original Host/Proto so the backend can derive
        // public endpoint URLs (e.g. the API/MCP endpoint info box).
        xfwd: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: true,
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            { name: 'react-vendor', test: /node_modules[\\/](react|react-dom|react-router-dom)[\\/]/ },
            { name: 'query-vendor', test: /node_modules[\\/]@tanstack[\\/]react-query[\\/]/ },
            { name: 'ui-vendor', test: /node_modules[\\/]@radix-ui[\\/]react-(dialog|dropdown-menu|select)[\\/]/ },
          ],
        },
      },
    },
  },
})
