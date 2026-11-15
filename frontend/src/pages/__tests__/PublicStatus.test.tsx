import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import PublicStatus, { bannerFor } from '../PublicStatus';

const get = vi.hoisted(() => vi.fn());
vi.mock('@/services/publicStatus.service', () => ({ publicStatusService: { get } }));

const g = (key: string, status: string) => ({ key, name: key, status, history: [] });
const renderPage = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <PublicStatus />
      </MemoryRouter>
    </QueryClientProvider>,
  );

describe('bannerFor', () => {
  const six = (statuses: string[]) => statuses.map((s) => ({ status: s }) as never);
  it('maps group statuses to a banner', () => {
    expect(bannerFor(six(Array(6).fill('ok')))).toBe('operational');
    expect(bannerFor(six(['ok', 'degraded', 'ok', 'ok', 'ok', 'ok']))).toBe('partial');
    expect(bannerFor(six(['down', 'down', 'ok', 'ok', 'ok', 'ok']))).toBe('partial');
    expect(bannerFor(six(['down', 'down', 'down', 'ok', 'ok', 'ok']))).toBe('major');
  });
});

describe('PublicStatus page', () => {
  it('renders groups with text status labels and a 72-bar strip', async () => {
    get.mockResolvedValue({ status: 'down', updated_at: null, groups: [g('a', 'ok'), g('c', 'ok'), g('b', 'down')] });
    renderPage();
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Partial outage'));
    expect(screen.getByTestId('group-b')).toHaveTextContent('Outage');
    expect(screen.getByTestId('group-a')).toHaveTextContent('Operational');
    expect(screen.getByTestId('group-a').querySelectorAll('[title]')).toHaveLength(72);
  });

  it('opens with the OPBX Cloud disclaimer, above the title', async () => {
    get.mockResolvedValue({ status: 'ok', updated_at: null, groups: [g('a', 'ok')] });
    renderPage();
    const notice = screen.getByRole('complementary', { name: 'Disclaimer' });
    for (const clause of ['Demonstration service only', 'No warranty; no service level', 'Use at your own risk', 'Limitation of liability']) {
      expect(notice).toHaveTextContent(clause);
    }
    expect(notice).toHaveTextContent(/without prior notice/);
    const title = screen.getByRole('heading', { level: 1, name: 'Platform Status' });
    expect(notice.compareDocumentPosition(title) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    await waitFor(() => expect(screen.getByRole('status')).toBeInTheDocument());
  });

  it('shows a friendly error when the request fails', async () => {
    get.mockRejectedValue(new Error('boom'));
    renderPage();
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('could not load the status'));
  });
});
