import { BrowserRouter, Routes } from 'react-router-dom';
import { AuthProvider } from './auth/AuthContext';
import { ErrorBoundary } from './components/ErrorBoundary';
import { routeTree } from './routes';

/** The route table, separate from the router so tests can mount it in a memory router. */
export function AppRoutes() {
  return <Routes>{routeTree}</Routes>;
}

export default function App() {
  return (
    <ErrorBoundary scope="app">
      <BrowserRouter>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </BrowserRouter>
    </ErrorBoundary>
  );
}
