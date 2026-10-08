import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// In development the SPA calls /api on its own origin; Vite forwards it to Laravel.
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': process.env.VITE_DEV_API_PROXY ?? 'http://localhost:8000',
    },
  },
})
