import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// In development the SPA calls /api on its own origin; Vite forwards it to Laravel.
// VITE_ALLOWED_HOSTS (comma separated) lists extra host names the dev server answers to, e.g. an alias used by the other products.
const allowedHosts = (process.env.VITE_ALLOWED_HOSTS ?? '')
  .split(',')
  .map((host) => host.trim())
  .filter(Boolean)

export default defineConfig({
  plugins: [react()],
  server: {
    allowedHosts,
    proxy: {
      '/api': process.env.VITE_DEV_API_PROXY ?? 'http://localhost:8000',
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
