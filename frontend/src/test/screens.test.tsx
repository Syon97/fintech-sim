import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import { AlertQueue } from '../pages/AlertQueue';
import { humaneMessage } from '../api';

function mockFetch(body: unknown, status = 200) {
  vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(body), { status })));
}
afterEach(() => vi.unstubAllGlobals());

describe('AlertQueue', () => {
  it('shows an explanation sentence and a risk word with the number', async () => {
    mockFetch({ data: [{ id: 1, status: 'open', explanation: 'Maya received RM 12,000 in one go.', risk_score: 82, occurred_at: '2026-01-10T09:00:00Z', assignee: null }] });
    render(<MemoryRouter><AlertQueue /></MemoryRouter>);
    expect(await screen.findByText('Maya received RM 12,000 in one go.')).toBeInTheDocument();
    expect(screen.getByText(/High risk \(82\)/)).toBeInTheDocument();
  });
  it('has a friendly empty state', async () => {
    mockFetch({ data: [] });
    render(<MemoryRouter><AlertQueue /></MemoryRouter>);
    expect(await screen.findByText(/Nothing here right now/)).toBeInTheDocument();
  });
  it('shows a humane error and not a stack trace', async () => {
    mockFetch({}, 500);
    render(<MemoryRouter><AlertQueue /></MemoryRouter>);
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Nothing was changed'));
  });
});

describe('humaneMessage', () => {
  it('throttle', () => expect(humaneMessage(429)).toMatch(/wait a minute/));
  it('prefers the server message on 422', () => expect(humaneMessage(422, 'Those details do not match.')).toBe('Those details do not match.'));
});