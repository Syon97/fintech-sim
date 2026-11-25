import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { AuthProvider, useAuth } from './auth';
import { Login } from './pages/Login';
import { AlertQueue } from './pages/AlertQueue';
import { AlertDetail } from './pages/AlertDetail';

function Shell({ children }: { children: ReactNode }) {
  const { me, loading, logout } = useAuth();
  if (loading) return <p className="shell muted">Loading…</p>;
  if (!me) return <Navigate to="/login" replace />;
  return (
    <div className="shell">
      <header className="bar">
        <strong>Case desk</strong>
        <nav>
          <span className="muted">{me.name ?? me.username}</span>
          <button onClick={() => void logout()}>Sign out</button>
        </nav>
      </header>
      <main>{children}</main>
      <p className="muted">Synthetic data. Nothing here is a real customer.</p>
    </div>
  );
}

export function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/alerts" element={<Shell><AlertQueue /></Shell>} />
          <Route path="/alerts/:id" element={<Shell><AlertDetail /></Shell>} />
          <Route path="*" element={<Navigate to="/alerts" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
