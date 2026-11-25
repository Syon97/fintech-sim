import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { Problem, SkeletonRows } from '../components';
import { api, ApiError, unwrap } from '../api';
import { exactTime, relativeTime } from '../format';
import { OUTCOMES, type CaseDetail as Detail, type Person } from '../types';

type Mode = null | 'note' | 'assign' | 'freeze' | 'unfreeze' | 'close';
const PREVIEW = 10;

export function CaseDetail() {
  const { id } = useParams();
  const [c, setC] = useState<Detail | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [mode, setMode] = useState<Mode>(null);
  const [busy, setBusy] = useState(false);
  const [text, setText] = useState('');
  const [pick, setPick] = useState('');
  const [people, setPeople] = useState<Person[]>([]);
  const [showAll, setShowAll] = useState(false);

  const load = useCallback(async () => {
    try { setC(unwrap<Detail>(await api<unknown>(`/cases/${id}`))); }
    catch (e: any) { setProblem(e.message); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  function choose(m: Mode) {
    setMode(m); setText(''); setProblem(null);
    setPick(m === 'close' && c?.must_escalate ? 'escalated_to_compliance' : '');
    if (m === 'assign' && people.length === 0) {
      api<unknown>('/case-assignees').then((b) => setPeople(unwrap<Person[]>(b))).catch((e) => setProblem(e.message));
    }
  }

  // None of these are optimistic: assigning, freezing and closing change who may do
  // what (or money movement), so the screen only changes once the server confirms.
  async function submit() {
    const bodies: Record<Exclude<Mode, null>, [string, unknown]> = {
      note: ['notes', { note: text }],
      assign: ['assign', { to: pick }],
      freeze: ['freeze', { reason: text }],
      unfreeze: ['unfreeze', { reason: text }],
      close: ['close', { outcome: pick, note: text }],
    };
    if (!mode) return;
    const [path, body] = bodies[mode];
    setBusy(true); setProblem(null);
    try {
      await api(`/cases/${id}/${path}`, { method: 'POST', body });
      setMode(null); setText(''); setPick('');
      await load();
    } catch (e: any) {
      setProblem(e.message);
      if (e instanceof ApiError && e.status === 409) await load();
    } finally { setBusy(false); }
  }

  if (problem && !c) return <Problem message={problem} onRetry={() => { setProblem(null); void load(); }} />;
  if (!c) return <SkeletonRows n={3} label="Loading case…" />;

  const closed = c.status === 'closed';
  const alerts = showAll ? c.alerts : c.alerts.slice(0, PREVIEW);
  const frozen = c.account.status === 'frozen';
  const ready = mode === 'assign' ? pick !== '' : mode === 'close' ? pick !== '' && text.trim() !== '' : text.trim() !== '';

  return (
    <article>
      <p><Link to="/cases">← Back to cases</Link></p>
      <h1>{c.account.number}</h1>
      <p>
        {closed ? `Closed: ${c.outcome_label ?? c.outcome}.` : 'Open.'}{' '}
        The account is <strong>{frozen ? 'frozen' : c.account.status}</strong>.
        <span className="muted"> Owned by {c.owner?.name ?? 'nobody yet'}. Opened <time dateTime={c.opened_at} title={exactTime(c.opened_at)}>{relativeTime(c.opened_at)}</time>.</span>
      </p>
      {closed && c.closing_note && <p>Closing note: “{c.closing_note}”</p>}
      {problem && <Problem message={problem} />}

      {!closed && (
        <>
          <div className="actions">
            {c.can.add_note && <button onClick={() => choose('note')}>Add a note</button>}
            {c.can.assign && <button onClick={() => choose('assign')}>Hand to someone else</button>}
            {c.can.freeze && <button onClick={() => choose('freeze')}>Freeze the account</button>}
            {c.can.unfreeze && <button onClick={() => choose('unfreeze')}>Unfreeze the account</button>}
            {c.can.close && <button onClick={() => choose('close')}>Close the case</button>}
          </div>
          {!c.can.close && c.close_blockers.length > 0 && (
            <p className="muted">This case cannot be closed yet: {c.close_blockers.join(' ')}</p>
          )}
          {c.must_escalate && <p className="muted">This case must be escalated to compliance before it can be closed.</p>}
        </>
      )}

      {mode && (
        <form onSubmit={(e) => { e.preventDefault(); void submit(); }} aria-label="Case action">
          {mode === 'freeze' && <p>Freezing stops money moving in or out of this account until someone unfreezes it. Your reason is kept in the history.</p>}
          {mode === 'unfreeze' && <p>The account will be able to move money again. Your reason is kept in the history.</p>}
          {mode === 'close' && <p>Closing is final. The outcome and note are kept in the history.</p>}
          {mode === 'assign' && (
            <div className="field">
              <label htmlFor="to">Who should own this case?</label>
              <select id="to" value={pick} onChange={(e) => setPick(e.target.value)} required>
                <option value="">Choose a person…</option>
                {people.map((p) => <option key={p.username} value={p.username}>{p.name}</option>)}
              </select>
            </div>
          )}
          {mode === 'close' && (
            <div className="field">
              <label htmlFor="outcome">What is the outcome?</label>
              <select id="outcome" value={pick} onChange={(e) => setPick(e.target.value)} required>
                <option value="">Choose one…</option>
                {OUTCOMES.map((o) => (
                  <option key={o.value} value={o.value} disabled={c.must_escalate && o.value !== 'escalated_to_compliance'}>{o.label}</option>
                ))}
              </select>
            </div>
          )}
          {mode !== 'assign' && (
            <div className="field">
              <label htmlFor="text">{mode === 'note' || mode === 'close' ? 'Note' : 'Reason'}</label>
              <textarea id="text" rows={3} value={text} onChange={(e) => setText(e.target.value)} required />
            </div>
          )}
          <div className="actions">
            <button className="primary" disabled={busy || !ready}>
              {{ note: 'Save note', assign: 'Hand over', freeze: 'Yes, freeze it', unfreeze: 'Yes, unfreeze it', close: 'Yes, close the case' }[mode]}
            </button>
            <button type="button" onClick={() => setMode(null)} disabled={busy}>Cancel</button>
          </div>
        </form>
      )}

      <h2>Alerts in this case ({c.alerts.length})</h2>
      <ul className="list">
        {alerts.map((a) => (
          <li className="row" key={a.id}>
            <Link className="title" to={`/alerts/${a.id}`}>{a.explanation}</Link>
            <span className="muted">{a.status === 'closed' ? `Closed: ${a.disposition}` : a.assignee ? `With ${a.assignee}` : 'Not picked up'}</span>
          </li>
        ))}
      </ul>
      {c.alerts.length > PREVIEW && <p><button onClick={() => setShowAll(!showAll)}>{showAll ? 'Show fewer' : `Show all ${c.alerts.length}`}</button></p>}

      <h2>What has happened</h2>
      <ol className="timeline">
        {c.timeline.map((t, i) => (
          <li key={i}>
            {t.sentence}
            {t.note && <div>“{t.note}”</div>}
            <span className="muted"> <time dateTime={t.at} title={exactTime(t.at)}>{relativeTime(t.at)}</time></span>
          </li>
        ))}
      </ol>
    </article>
  );
}
