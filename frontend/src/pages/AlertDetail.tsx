import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, unwrap } from '../api';
import { exactTime, relativeTime, riskBand, riskWord } from '../format';
import type { AlertDetail as Detail } from '../types';

export function AlertDetail() {
  const { id } = useParams();
  const [a, setA] = useState<Detail | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [reason, setReason] = useState('');

  const load = useCallback(async () => {
    try { setA(unwrap<Detail>(await api<unknown>(`/alerts/${id}`))); setProblem(null); }
    catch (e: any) { setProblem(e.message); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  // Reversible actions (start, release) update the screen at once and roll back
  // on failure. Closing is irreversible, so it waits for the server.
  async function act(path: 'start' | 'release' | 'close', body?: unknown) {
    if (!a) return;
    const before = a;
    setBusy(true); setProblem(null);
    if (path === 'start') setA({ ...a, status: 'investigating' });
    if (path === 'release') setA({ ...a, status: 'open', assignee: null });
    try {
      await api(`/alerts/${a.id}/${path}`, { method: 'POST', body });
      await load();
    } catch (e: any) { setA(before); setProblem(e.message); }
    finally { setBusy(false); }
  }

  if (problem && !a) return <div className="problem" role="alert">{problem}</div>;
  if (!a) return <p className="muted">Loading alert…</p>;

  return (
    <article>
      <p><Link to="/alerts">← Back to alerts</Link></p>
      <h1>{a.explanation}</h1>
      <p>
        <span className={`risk num ${riskBand(a.score)}`}>{a.score_label ?? riskWord(a.score)} ({a.score})</span>
        {a.score_sentence && <> — {a.score_sentence}</>}
      </p>
      <p className="muted">
        Raised <time dateTime={a.created_at} title={exactTime(a.created_at)}>{relativeTime(a.created_at)}</time>
        {a.assignee ? `, with ${a.assignee}` : ', not picked up yet'}.
      </p>
      {problem && <div className="problem" role="alert">{problem}</div>}
      {a.status !== 'closed' && (
        <div className="actions">
          {a.can?.start && <button className="primary" disabled={busy} onClick={() => act('start')}>Start looking at this</button>}
          {a.can?.release && <button disabled={busy} onClick={() => act('release')}>Put it back in the queue</button>}
        </div>
      )}
      {a.status !== 'closed' && a.can?.close && (
        <form onSubmit={(e) => { e.preventDefault(); void act('close', { reason }); }}>
          <div className="field">
            <label htmlFor="why">Why is it safe to close? (required, recorded in the history)</label>
            <textarea id="why" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} required />
          </div>
          <button disabled={busy || reason.trim() === ''}>Close alert</button>
        </form>
      )}
      <h2>What has happened</h2>
      {a.timeline?.length ? (
        <ol className="timeline">
          {a.timeline.map((t, i) => (
            <li key={i}>{t.sentence} <span className="muted"><time dateTime={t.at} title={exactTime(t.at)}>{relativeTime(t.at)}</time></span></li>
          ))}
        </ol>
      ) : <p className="muted">No activity yet.</p>}
    </article>
  );
}
