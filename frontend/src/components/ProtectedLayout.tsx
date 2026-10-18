import { RequireAuth } from '../auth/RequireAuth';
import { Layout } from './Layout';

/** Every page except sign-in: needs a session and shares one Layout, which stays mounted while you move between pages. */
export function ProtectedLayout() {
  return (
    <RequireAuth>
      <Layout />
    </RequireAuth>
  );
}
