import { useCallback, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { api, unwrap } from '../api';
import { exactTime, relativeTime, riskBand, riskWord } from '../format';
import { DISPOSITIONS, type AlertDetail as Detail } from '../types';

const EVIDENCE_PREVIEW = 8;

export function AlertDetail() {
  const { id } = useParams();
  const [a, setA] = useState<Detail | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [disposition, setDisposition] = useState('');
  const [note, setNote] = useState('');
  const [showAll, setShowAll] = useState(false);

  const load = useCallback(async () => {
    try { setA(unwrap<Detail>(await api<unknown>(`/alerts/${id}`))); }
    catch (e: any) { setProblem(e.message); }
  }, [id]);
  useEffect(() => { void load(); }, [load]);

  // Reversible actions (start, release) update the screen at once and roll back on
  // failure. Closing is final, so it waits for the server before changing anything.
  async function act(path: 'start' | 'release' | 'close', body?: unknown) {
    if (!a) return;
    const before = a;
    setBusy(true); setProblem(null);
    if (path === 'start') setA({ ...a, status: 'investigating' });
    if (path === 'release') setA({ ...a, status: 'open', assignee: null });
    try {
      await api(`/alerts/${a.id}/${path}`, { method: 'POST', body });
      await load();
    } catch (e: any) {
      setA(before); setProblem(e.message);
      await load(); // someone else may have moved it on; show the truth
    } finally { setBusy(false); }
  }

  if (problem && !a) return <div className="problem" role="alert">{problem}</div>;
  if (!a) return <p className="muted">Loading alert…</p>;

  const evidence = showAll ? a.evidence : a.evidence.slice(0, EVIDENCE_PREVIEW);
  const closed = a.status === 'closed';

  return (
    <article>
      <p><Link to="/alerts">← Back to alerts</Link></p>
      <h1>{a.explanation}</h1>
      <p>
        <span className={`risk num ${riskBand(a.risk_score)}`}>{riskWord(a.risk_score)} ({a.risk_score})</span>
        <span className="muted"> · happened <time dateTime={a.occurred_at} title={exactTime(a.occurred_at)}>{relativeTime(a.occurred_at)}</time>
        {a.assignee ? `, with ${a.assignee}` : ', not picked up yet'}</span>
      </p>
      {closed && <p>Closed as <strong>{a.disposition}</strong>{a.closed_at && <> <time dateTime={a.closed_at} title={exactTime(a.closed_at)}>{relativeTime(a.closed_at)}</time></>}.</p>}
      {problem && <div className="problem" role="alert">{problem}</div>}

      {!closed && (a.can?.start || a.can?.release) && (
        <div className="actions">
          {a.can?.start && <button className="primary" disabled={busy} onClick={() => act('start')}>Start looking at this</button>}
          {a.can?.release && <button disabled={busy} onClick={() => act('release')}>Put it back in the queue</button>}
        </div>
      )}

      <h2>Why it was raised</h2>
      <p>{a.rule.name} (version {a.rule.version}). {a.rule.rationale}</p>

      <h2>How the score was reached</h2>
      <ul>{a.score.sentences.map((s, i) => <li key={i}>{s}</li>)}</ul>
      {a.group && a.group.alert_count > 1 && (
        <p className="muted">This account has {a.group.alert_count} alerts in the same run, {a.group.unresolved} not yet resolved.</p>
      )}

      <h2>The transactions behind it ({a.evidence.length})</h2>
      <div className="scroll">
        <table>
          <caption className="muted">Money in and out of the account, as the rule saw it</caption>
          <thead><tr><th scope="col">When</th><th scope="col">Direction</th><th scope="col" className="r">Amount</th></tr></thead>
          <tbody>
            {evidence.map((e) => (
              <tr key={e.transaction_id}>
                <td><time dateTime={e.occurred_at} title={exactTime(e.occurred_at)}>{exactTime(e.occurred_at)}</time></td>
                <td>{e.direction === 'C' ? 'Money in' : 'Money out'}</td>
                <td className="r num">{e.amount_display}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {a.evidence.length > EVIDENCE_PREVIEW && (
        <p><button onClick={() => setShowAll(!showAll)}>{showAll ? 'Show fewer' : `Show all ${a.evidence.length}`}</button></p>
      )}

      {!closed && a.can?.close && (
        <>
          <h2>Close this alert</h2>
          <p className="muted">Closing is final. Your note goes into the history.</p>
          <form onSubmit={(e) => { e.preventDefault(); void act('close', { disposition, note }); }}>
            <div className="field">
              <label htmlFor="disp">What did you find?</label>
              <select id="disp" value={disposition} onChange={(e) => setDisposition(e.target.value)} required>
                <option value="">Choose one…</option>
                {DISPOSITIONS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
              </select>
            </div>
            <div className="field">
              <label htmlFor="note">Note</label>
              <textarea id="note" rows={3} value={note} onChange={(e) => setNote(e.target.value)} required />
            </div>
            <button disabled={busy || !disposition || note.trim() === ''}>Close alert</button>
          </form>
        </>
      )}

      <h2>What has happened</h2>
      <ol className="timeline">
        {a.timeline.map((t, i) => (
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