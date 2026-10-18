import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  // The dev server only answers to hostnames named here (exact, comma separated; e.g. VITE_ALLOWED_HOSTS=nexus.localtest.me).
  // `localhost` and IP addresses are always allowed by Vite itself; never set allowedHosts to true.
  const allowedHosts = (env.VITE_ALLOWED_HOSTS ?? '')
    .split(',')
    .map((host) => host.trim())
    .filter(Boolean)

  return {
    plugins: [react()],
    server: allowedHosts.length > 0 ? { allowedHosts } : {},
  }
})
