import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, unwrap } from '../api';
import { exactTime, relativeTime, riskBand, riskWord } from '../format';
import type { AlertSummary } from '../types';

const FILTERS = [
  { key: 'open', label: 'Waiting for someone' },
  { key: 'investigating', label: 'Being looked at' },
  { key: 'closed', label: 'Closed' },
] as const;

export function AlertQueue() {
  const [filter, setFilter] = useState<(typeof FILTERS)[number]['key']>('open');
  const [items, setItems] = useState<AlertSummary[] | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    setItems(null); setProblem(null);
    api<unknown>('/alerts?status=' + filter)
      .then((b) => live && setItems(unwrap<AlertSummary[]>(b)))
      .catch((e) => live && setProblem(e.message));
    return () => { live = false; };
  }, [filter]);

  return (
    <section>
      <h1>Alerts</h1>
      <div className="tabs" role="group" aria-label="Show alerts that are">
        {FILTERS.map((f) => (
          <button key={f.key} aria-pressed={filter === f.key} onClick={() => setFilter(f.key)}>{f.label}</button>
        ))}
      </div>
      {problem && <div className="problem" role="alert">{problem}</div>}
      {!problem && items === null && <p className="muted">Loading alerts…</p>}
      {items && items.length === 0 && (
        <p className="empty">Nothing here right now. New alerts appear when a transaction trips a rule.</p>
      )}
      {items && items.length > 0 && (
        <ul className="list">
          {items.map((a) => (
            <li className="row" key={a.id}>
              <div>
                <Link className="title" to={`/alerts/${a.id}`}>{a.explanation}</Link>
                <div className="muted">
                  <time dateTime={a.created_at} title={exactTime(a.created_at)}>{relativeTime(a.created_at)}</time>
                  {a.assignee ? ` — with ${a.assignee}` : ' — not picked up yet'}
                </div>
              </div>
              <span className={`risk num ${riskBand(a.score)}`}>{a.score_label ?? riskWord(a.score)} ({a.score})</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
