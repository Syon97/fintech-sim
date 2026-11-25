import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { Problem, SkeletonRows } from '../components';
import { useFetch } from '../hooks';
import { useAuth } from '../auth';
import { exactTime, relativeTime, riskBand, riskWord } from '../format';
import type { CaseSummary, Group } from '../types';

export function Groups() {
  const { me } = useAuth();
  const nav = useNavigate();
  const canManage = me?.permissions.includes('cases.manage') ?? false;
  const { data: groups, problem: loadProblem, reload } = useFetch<Group[]>('/groups');
  const [actionProblem, setProblem] = useState<string | null>(null);
  const [opening, setOpening] = useState<number | null>(null);
  const problem = loadProblem ?? actionProblem;

  async function open(g: Group) {
    setOpening(g.id); setProblem(null);
    try {
      const c = await api<CaseSummary>(`/groups/${g.id}/case`, { method: 'POST', body: {} });
      nav(`/cases/${c.id}`);
    } catch (e: any) { setProblem(e.message); setOpening(null); }
  }

  return (
    <section>
      <h1>Accounts with alerts</h1>
      <p className="muted">Alerts on the same account that arrive close together are grouped. Open a case when a group needs someone to own it.</p>
      {problem && <Problem message={problem} onRetry={loadProblem ? reload : undefined} />}
      {!problem && groups === null && <SkeletonRows label="Loading accounts…" />}
      {groups && groups.length === 0 && <p className="empty">No groups need attention right now.</p>}
      {groups && groups.length > 0 && (
        <ul className="list">
          {groups.map((g) => (
            <li className="row" key={g.id}>
              <div>
                <strong>{g.account_number}</strong>
                <div className="muted">
                  {g.unresolved} of {g.alert_count} alerts still need a decision, last one{' '}
                  <time dateTime={g.last_alert_at} title={exactTime(g.last_alert_at)}>{relativeTime(g.last_alert_at)}</time>
                </div>
              </div>
              <div>
                <span className={`risk num ${riskBand(g.max_score)}`}>{riskWord(g.max_score)} ({g.max_score})</span>{' '}
                {g.case?.id
                  ? <Link to={`/cases/${g.case.id}`}>View case</Link>
                  : canManage && <button disabled={opening !== null} onClick={() => open(g)}>{opening === g.id ? 'Opening…' : 'Open a case'}</button>}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
