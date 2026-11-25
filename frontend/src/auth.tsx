import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { api, ApiError, unwrap } from './api';
import type { Me } from './types';

interface AuthState {
  me: Me | null;
  loading: boolean;
  login(username: string, password: string): Promise<void>;
  logout(): Promise<void>;
}
const Ctx = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api<unknown>('/me')
      .then((b) => setMe(unwrap<Me>(b)))
      .catch((e) => { if (!(e instanceof ApiError) || e.status !== 401) console.error(e); })
      .finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (username: string, password: string) => {
    await api('/login', { method: 'POST', body: { username, password } });
    setMe(unwrap<Me>(await api<unknown>('/me')));
  }, []);
  const logout = useCallback(async () => {
    await api('/logout', { method: 'POST' });
    setMe(null);
  }, []);

  return <Ctx.Provider value={{ me, loading, login, logout }}>{children}</Ctx.Provider>;
}

export function useAuth(): AuthState {
  const v = useContext(Ctx);
  if (!v) throw new Error('useAuth outside AuthProvider');
  return v;
}
