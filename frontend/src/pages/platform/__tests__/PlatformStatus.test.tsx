import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PlatformStatusSnapshot } from '@/types/platform';

vi.mock('@/components/platform', () => ({ PlatformLayout: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const get = vi.fn();
const checkNow = vi.fn();
vi.mock('@/services/platformApi', () => ({ platformApi: { status: { get: (...a: unknown[]) => get(...a), checkNow: (...a: unknown[]) => checkNow(...a) } } }));

import PlatformStatus from '@/pages/platform/PlatformStatus';
import { StatusStrip } from '@/components/platform/ComponentStatusCard';

const comp = (over: object) => ({
  component: 'redis', label: 'Redis', optional: false, status: 'ok', latency_ms: 3,
  detail: 'PONG', checked_at: '2026-10-13T10:00:00Z', history: [], ...over,
});
const snap = (components: object[]): PlatformStatusSnapshot => ({
  checked_at: '2026-10-13T10:00:00Z', summary: { ok: 0, degraded: 0, down: 0, unknown: 0 }, components,
} as PlatformStatusSnapshot);

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}><MemoryRouter><PlatformStatus /></MemoryRouter></QueryClientProvider>,
  );
}

describe('PlatformStatus page', () => {
  beforeEach(() => { get.mockReset(); checkNow.mockReset(); });

  it('says all systems operational and greys an optional component that is not running', async () => {
    get.mockResolvedValue(snap([comp({}), comp({ component: 'loki', label: 'Loki', optional: true, status: 'unknown', detail: 'Not running (optional)' })]));
    renderPage();
    expect(await screen.findByText('All systems operational')).toBeTruthy();
    expect(screen.getAllByText('Not running (optional)').length).toBeGreaterThan(0);
  });

  it('highlights a down component in the summary and card, with text labels', async () => {
    get.mockResolvedValue(snap([comp({ component: 'mysql', label: 'MySQL (main)', status: 'down', detail: 'Check failed' }), comp({})]));
    renderPage();
    expect(await screen.findByText('1 component degraded or down')).toBeTruthy();
    expect(screen.getByText(/MySQL \(main\) \(down\)/)).toBeTruthy();
    expect(screen.getByText('Down')).toBeTruthy();
    expect(screen.getByTestId('component-mysql').className).toContain('border-red-600');
  });

  it('Check now runs the mutation and shows the returned snapshot', async () => {
    get.mockResolvedValue(snap([comp({})]));
    checkNow.mockResolvedValue(snap([comp({ status: 'degraded', detail: 'slow' })]));
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: /check now/i }));
    expect(await screen.findByText('1 component degraded or down')).toBeTruthy();
    expect(checkNow).toHaveBeenCalledTimes(1);
  });

  it('shows the shared error state when loading fails', async () => {
    get.mockRejectedValue(new Error('boom'));
    renderPage();
    await waitFor(() => expect(screen.getByRole('alert')).toBeTruthy());
    expect(screen.getByText('Failed to load platform status')).toBeTruthy();
  });
});

describe('StatusStrip', () => {
  it('renders 48 clock-aligned half-hour bars, each showing the worst status in its half hour', () => {
    const now = Date.parse('2026-10-13T12:10:00Z');
    const { container } = render(
      <StatusStrip
        now={now}
        history={[
          { checked_at: '2026-10-13T11:00:00Z', status: 'ok' },
          { checked_at: '2026-10-13T11:05:00Z', status: 'down' },
          { checked_at: '2026-10-13T11:25:00Z', status: 'degraded' },
          { checked_at: '2026-10-13T12:05:00Z', status: 'ok' },
        ]}
      />,
    );
    const bars = container.querySelectorAll('[role="img"] > div');
    expect(bars.length).toBe(48);
    // 12:00-12:30 is the current half hour (last bar), 11:30-12:00 had no checks, 11:00-11:30 was down at worst.
    expect(bars[47].className).toContain('bg-green-500');
    expect(bars[46].className).toContain('bg-muted');
    expect(bars[45].className).toContain('bg-red-600');
    expect(screen.getByRole('img').getAttribute('aria-label')).toContain('1 down and 0 degraded');
  });

  it('shows the time of the deciding check while a bar is hovered', () => {
    const now = Date.parse('2026-10-13T12:10:00Z');
    const checkAt = '2026-10-13T11:07:42Z';
    const { container } = render(
      <StatusStrip now={now} history={[{ checked_at: '2026-10-13T11:05:00Z', status: 'down', check_at: checkAt }]} />,
    );
    const bars = container.querySelectorAll('[role="img"] > div');
    const time = new Date(checkAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });

    expect(screen.getByText('24h ago')).toBeInTheDocument();
    fireEvent.mouseEnter(bars[45]);
    expect(screen.getByText(`Down · checked ${time}`)).toBeInTheDocument();
    fireEvent.mouseLeave(screen.getByRole('img'));
    expect(screen.getByText('24h ago')).toBeInTheDocument();
  });
});
