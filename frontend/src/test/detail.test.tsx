import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { vi } from 'vitest';
import { AlertDetail } from '../pages/AlertDetail';

const alert = {
  id: 1, status: 'open', severity: 'high', risk_score: 90, account_number: 'A1',
  explanation: 'A1 received RM 20,246.00 and moved out RM 10,153.43 within 1 minute(s).',
  occurred_at: '2026-11-04T03:03:08+00:00', assignee: null, disposition: null, closed_at: null,
  rule: { code: 'R', name: 'Rapid in-out', version: 1, rationale: 'Money passing straight through.' },
  score: { total: 90, sentences: ['Starts at 60 because the rule\'s severity is high.', 'Adds 15 for repeats.'] },
  evidence: Array.from({ length: 12 }, (_, i) => ({ transaction_id: 't' + i, direction: i % 2 ? 'D' : 'C', amount_sen: 100, amount_display: 'RM 1.00', occurred_at: '2026-11-04T03:03:08+00:00' })),
  group: { id: 1, alert_count: 90, unresolved: 90, max_score: 90 },
  timeline: [{ at: '2026-11-04T03:03:17+00:00', actor: null, sentence: 'Raised by the rule.', note: null }],
  can: { start: true, release: false, close: false, reassign: false },
};

function renderIt() {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(alert), { status: 200 })));
  render(<MemoryRouter initialEntries={['/alerts/1']}><Routes><Route path="/alerts/:id" element={<AlertDetail />} /></Routes></MemoryRouter>);
}
afterEach(() => vi.unstubAllGlobals());

describe('AlertDetail', () => {
  it('explains the score in sentences and shows the rule rationale', async () => {
    renderIt();
    expect(await screen.findByText(/Starts at 60/)).toBeInTheDocument();
    expect(screen.getByText(/Money passing straight through/)).toBeInTheDocument();
  });
  it('previews evidence and offers the rest', async () => {
    renderIt();
    expect(await screen.findByRole('button', { name: 'Show all 12' })).toBeInTheDocument();
    expect(screen.getAllByRole('row')).toHaveLength(9); // header + 8
  });
  it('shows Start only because the server said can.start', async () => {
    renderIt();
    expect(await screen.findByRole('button', { name: /Start looking/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Close alert/ })).toBeNull();
  });
});