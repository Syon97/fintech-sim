import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth';
import { ApiError } from '../api';
import { Problem } from '../components';

export function Login() {
  const { login } = useAuth();
  const nav = useNavigate();
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true); setProblem(null);
    try { await login(username.trim(), password); nav('/alerts'); }
    catch (err) { setProblem(err instanceof ApiError ? err.message : 'Something went wrong. Please try again.'); }
    finally { setBusy(false); }
  }

  return (
    <main className="shell" style={{ maxWidth: '26rem' }}>
      <h1>Case desk</h1>
      <p className="muted">Sign in with your work username. This is a demo with synthetic data.</p>
      <form onSubmit={submit}>
        <div className="field">
          <label htmlFor="u">Username</label>
          <input id="u" autoComplete="username" value={username} onChange={(e) => setUsername(e.target.value)} required />
        </div>
        <div className="field">
          <label htmlFor="p">Password</label>
          <input id="p" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </div>
        {problem && <Problem message={problem} />}
        <button className="primary" disabled={busy}>{busy ? 'Signing in…' : 'Sign in'}</button>
      </form>
    </main>
  );
}
