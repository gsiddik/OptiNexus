import { configure, render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { AppRoutes } from '../App';
import { AuthProvider } from '../auth/AuthContext';
import { ErrorBoundary } from '../components/ErrorBoundary';

// Allow lazy route modules and the initial authenticated context to finish loading on shared CI runners.
configure({ asyncUtilTimeout: 3000 });

/** The real route table, auth provider and boundaries, mounted at `path` in a memory router. */
export function renderApp(path = '/') {
  return render(
    <ErrorBoundary scope="app">
      <MemoryRouter initialEntries={[path]}>
        <AuthProvider>
          <AppRoutes />
        </AuthProvider>
      </MemoryRouter>
    </ErrorBoundary>,
  );
}
