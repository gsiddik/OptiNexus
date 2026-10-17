import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { TOKEN_STORAGE_KEY } from './api/client'
import { storage } from './auth/storage'
import { installChunkErrorHandling } from './errors/chunkErrors'
import { preloadPageFor } from './routes'

installChunkErrorHandling()
// A signed-in browser opens the page of this address as soon as its session is confirmed: download that page's file meanwhile.
if (storage.get(TOKEN_STORAGE_KEY)) preloadPageFor(window.location.pathname)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)

// The first screen is up: the fallback in index.html (shown only when this file never ran) can stand down.
;(window as unknown as { __optinexusBooted?: boolean }).__optinexusBooted = true
document.getElementById('boot-fallback')?.remove()
