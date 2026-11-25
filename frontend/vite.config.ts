/// <reference types="vitest" />
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Everything is same-origin: the browser only talks to localhost:5173 and Vite
// forwards /api and /sanctum to Laravel. That is what lets Sanctum's cookie
// session work without any CORS configuration.
const laravel = process.env.LARAVEL_URL ?? 'http://localhost:8000';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': { target: laravel, changeOrigin: false },
      '/sanctum': { target: laravel, changeOrigin: false },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
  },
});
