import { BrowserRouter, Navigate, NavLink, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { AuthProvider, useAuth } from './auth';
import { Login } from './pages/Login';
import { AlertQueue } from './pages/AlertQueue';
import { AlertDetail } from './pages/AlertDetail';
import { Groups } from './pages/Groups';
import { CaseList } from './pages/CaseList';
import { CaseDetail } from './pages/CaseDetail';

function Shell({ children }: { children: ReactNode }) {
  const { me, loading, logout } = useAuth();
  if (loading) return <p className="shell muted">Loading…</p>;
  if (!me) return <Navigate to="/login" replace />;
  return (
    <div className="shell">
      <a className="skip" href="#main">Skip to main content</a>
      <header className="bar">
        <strong>Case desk</strong>
        <nav aria-label="Main">
          <NavLink to="/alerts">Alerts</NavLink>
          {me.permissions.includes('cases.view') && <NavLink to="/groups">Accounts</NavLink>}
          {me.permissions.includes('cases.view') && <NavLink to="/cases">Cases</NavLink>}
          <span className="muted">{me.name ?? me.username}</span>
          <button onClick={() => void logout()}>Sign out</button>
        </nav>
      </header>
      <main id="main" tabIndex={-1}>{children}</main>
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
          <Route path="/groups" element={<Shell><Groups /></Shell>} />
          <Route path="/cases" element={<Shell><CaseList /></Shell>} />
          <Route path="/cases/:id" element={<Shell><CaseDetail /></Shell>} />
          <Route path="*" element={<Navigate to="/alerts" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
