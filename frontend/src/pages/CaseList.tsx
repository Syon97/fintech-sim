import { Link, useSearchParams } from 'react-router-dom';
import { Problem, SkeletonRows } from '../components';
import { useFetch } from '../hooks';
import { exactTime, relativeTime } from '../format';
import type { CaseSummary } from '../types';

export function CaseList() {
  const [params, setParams] = useSearchParams();
  const show = params.get('show') === 'closed' ? 'closed' : 'open';
  const { data: cases, problem, reload } = useFetch<CaseSummary[]>('/cases');

  const shown = cases?.filter((c) => c.status === show) ?? [];
  return (
    <section>
      <h1>Cases</h1>
      <div className="tabs" role="group" aria-label="Show cases that are">
        <button aria-pressed={show === 'open'} onClick={() => setParams({ show: 'open' })}>Open</button>
        <button aria-pressed={show === 'closed'} onClick={() => setParams({ show: 'closed' })}>Closed</button>
      </div>
      {problem && <Problem message={problem} onRetry={reload} />}
      {!problem && cases === null && <SkeletonRows label="Loading cases…" />}
      {cases && shown.length === 0 && <p className="empty">{show === 'open' ? 'No open cases. Open one from an account with alerts.' : 'No closed cases yet.'}</p>}
      <ul className="list">
        {shown.map((c) => (
          <li className="row" key={c.id}>
            <div>
              <Link className="title" to={`/cases/${c.id}`}>{c.account.number}</Link>
              <div className="muted">
                Opened <time dateTime={c.opened_at} title={exactTime(c.opened_at)}>{relativeTime(c.opened_at)}</time>
                {c.owner ? `, owned by ${c.owner.name}` : ''}
                {c.status === 'closed' && c.outcome_label ? `. Outcome: ${c.outcome_label}` : ''}
              </div>
            </div>
            <span className="num">{c.alerts.unresolved} of {c.alerts.total} alerts open{c.account.status === 'frozen' ? ', account frozen' : ''}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}
