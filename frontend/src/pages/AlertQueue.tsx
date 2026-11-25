import { Link, useSearchParams } from 'react-router-dom';
import { Problem, SkeletonRows } from '../components';
import { useFetch } from '../hooks';
import { exactTime, relativeTime, riskBand, riskWord } from '../format';
import type { AlertSummary } from '../types';

const FILTERS = [
  { key: 'open', label: 'Waiting for someone' },
  { key: 'investigating', label: 'Being looked at' },
  { key: 'closed', label: 'Closed' },
] as const;

export function AlertQueue() {
  // The filter lives in the address (?status=...) so a refresh or a shared link keeps it.
  const [params, setParams] = useSearchParams();
  const raw = params.get('status');
  const filter = FILTERS.some((f) => f.key === raw) ? raw! : 'open';
  const { data: items, problem, reload } = useFetch<AlertSummary[]>('/alerts?status=' + filter);

  return (
    <section>
      <h1>Alerts</h1>
      <div className="tabs" role="group" aria-label="Show alerts that are">
        {FILTERS.map((f) => (
          <button key={f.key} aria-pressed={filter === f.key} onClick={() => setParams({ status: f.key })}>{f.label}</button>
        ))}
      </div>
      {problem && <Problem message={problem} onRetry={reload} />}
      {!problem && items === null && <SkeletonRows label="Loading alerts…" />}
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
                  Happened <time dateTime={a.occurred_at} title={exactTime(a.occurred_at)}>{relativeTime(a.occurred_at)}</time>
                  {a.assignee ? `, with ${a.assignee}` : ', not picked up yet'}
                </div>
              </div>
              <span className={`risk num ${riskBand(a.risk_score)}`}>{riskWord(a.risk_score)} ({a.risk_score})</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
