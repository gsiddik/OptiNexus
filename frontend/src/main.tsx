import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { installChunkErrorHandling } from './errors/chunkErrors'

installChunkErrorHandling()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)

// The first screen is up: the fallback in index.html (shown only when this file never ran) can stand down.
;(window as unknown as { __optinexusBooted?: boolean }).__optinexusBooted = true
document.getElementById('boot-fallback')?.remove()
