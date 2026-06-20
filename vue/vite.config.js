import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// When running inside Docker the proxy must point to the nginx service name,
// not localhost (which would mean the Vue container itself).
// Set VITE_API_PROXY_TARGET=http://nginx:80 in docker-compose.yml.
// Outside Docker (local npm run dev) it falls back to http://localhost:8000.
const apiProxyTarget = process.env.VITE_API_PROXY_TARGET ?? 'http://localhost:8000'

export default defineConfig({
  plugins: [vue()],

  server: {
    port: 5173,
    host: '0.0.0.0',

    proxy: {
      // Every /api/... request is forwarded to Laravel.
      // The browser always talks to localhost:5173 — no CORS, no hardcoded URLs.
      '/api': {
        target: apiProxyTarget,
        changeOrigin: true,
        secure: false,
      },
    },
  },

  test: {
    environment: 'jsdom',
    globals: true,
  },
})
