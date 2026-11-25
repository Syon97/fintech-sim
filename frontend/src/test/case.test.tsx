import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { vi } from 'vitest';
import { CaseDetail } from '../pages/CaseDetail';

const base = {
  id: 1, status: 'open', outcome: null, outcome_label: null, closing_note: null,
  opened_at: '2026-11-25T02:48:30+00:00', closed_at: null,
  owner: { username: 's', name: 'Sofia' }, opened_by: { username: 's', name: 'Sofia' },
  account: { number: 'ACC-1', status: 'active' },
  group: { id: 19, alert_count: 2, max_score: 90, first_alert_at: '2026-11-04T03:03:04+00:00', last_alert_at: '2026-11-04T03:03:08+00:00' },
  alerts: [{ id: 5, status: 'open', severity: 'high', risk_score: 90, explanation: 'Big move.', rule_name: 'R', disposition: null, occurred_at: '2026-11-04T03:03:08+00:00', assignee: null }],
  timeline: [{ at: '2026-11-25T02:48:30+00:00', actor: null, sentence: 'Sofia opened this case.', note: null }],
  can: { add_note: true, assign: true, freeze: true, unfreeze: false, close: false },
  close_blockers: ['82 alerts still need a decision.'], must_escalate: false,
};

function setup(overrides = {}) {
  const calls: { url: string; init?: RequestInit }[] = [];
  vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
    calls.push({ url, init });
    return new Response(JSON.stringify({ ...base, ...overrides }), { status: 200 });
  }));
  render(<MemoryRouter initialEntries={['/cases/1']}><Routes><Route path="/cases/:id" element={<CaseDetail />} /></Routes></MemoryRouter>);
  return calls;
}
afterEach(() => vi.unstubAllGlobals());

describe('CaseDetail', () => {
  it('explains why it cannot be closed instead of offering Close', async () => {
    setup();
    expect(await screen.findByText(/82 alerts still need a decision/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Close the case' })).toBeNull();
  });
  it('only shows actions the server allows', async () => {
    setup();
    expect(await screen.findByRole('button', { name: 'Freeze the account' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Unfreeze the account' })).toBeNull();
  });
  it('freeze needs a reason, then posts it', async () => {
    const calls = setup();
    await userEvent.click(await screen.findByRole('button', { name: 'Freeze the account' }));
    expect(screen.getByRole('button', { name: 'Yes, freeze it' })).toBeDisabled();
    await userEvent.type(screen.getByLabelText('Reason'), 'Looks like mule activity');
    await userEvent.click(screen.getByRole('button', { name: 'Yes, freeze it' }));
    await waitFor(() => {
      const post = calls.find((c) => c.url.endsWith('/cases/1/freeze'));
      expect(post).toBeTruthy();
      expect(JSON.parse(post!.init!.body as string)).toEqual({ reason: 'Looks like mule activity' });
    });
  });
  it('locks the outcome to escalation when the server says it must escalate', async () => {
    setup({ can: { ...base.can, close: true }, close_blockers: [], must_escalate: true });
    await userEvent.click(await screen.findByRole('button', { name: 'Close the case' }));
    expect(screen.getByLabelText('What is the outcome?')).toHaveValue('escalated_to_compliance');
    expect(screen.getByRole('option', { name: 'No further action needed' })).toBeDisabled();
  });
});