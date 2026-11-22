import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import AiMonitor from '../AiMonitor';
import { healthOf } from '../AiMonitor/widgets';

const snapshot = vi.hoisted(() => vi.fn());
vi.mock('@/services/aiMonitor.service', () => ({ aiMonitorService: { snapshot } }));

const assistant = (id: number, name: string, extra = {}) => ({
  id, name, provider: 'openai', status: 'active', active: 1, calls: 10, answered: 9, failed: 1, success_rate: 90,
  avg_duration_seconds: 125, ai_minutes: 19, last_call_at: new Date().toISOString(), series: Array(12).fill(1), ...extra,
});
const loadBalancer = {
  id: 7, name: 'Main AI Pool', strategy: 'percentage', status: 'active', active: 2, calls: 20, failovers: 3, fell_back: 1,
  handled_rate: 95, series: Array(12).fill(2),
  members: [{ ai_assistant_id: 1, name: 'Sales Bot', active: true, priority: 1, calls: 12, actual_share: 60, configured_share: 50 }],
};
const data = (over = {}) => ({ window_minutes: 60, bucket_seconds: 300, generated_at: '', assistants: [assistant(1, 'Sales Bot')], load_balancers: [loadBalancer], ...over });

const renderPage = () =>
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <AiMonitor />
    </QueryClientProvider>,
  );

describe('healthOf', () => {
  it('colours by failure share and greys out an assistant without calls', () => {
    expect(healthOf(97, 10)).toBe('ok');
    expect(healthOf(92, 10)).toBe('warn');
    expect(healthOf(80, 10)).toBe('bad');
    expect(healthOf(null, 0)).toBe('idle');
  });
});

describe('AiMonitor page', () => {
  beforeEach(() => {
    localStorage.clear();
    snapshot.mockReset();
    snapshot.mockResolvedValue(data());
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] });
  });
  afterEach(() => vi.useRealTimers());

  it('opens on the assistants view for the last 60 minutes', async () => {
    renderPage();
    const card = await screen.findByTestId('assistant-1');
    expect(card).toHaveTextContent('Sales Bot');
    expect(card).toHaveTextContent('2:05');
    expect(card.dataset.health).toBe('warn'); // 10 % failed
    expect(screen.getByRole('img', { name: 'success: 90%' })).toBeInTheDocument();
    expect(screen.queryByTestId('load-balancer-7')).not.toBeInTheDocument();
    expect(snapshot).toHaveBeenCalledWith(60);
  });

  it('switches to the load balancers view after 30 seconds', async () => {
    renderPage();
    await screen.findByTestId('assistant-1');
    act(() => vi.advanceTimersByTime(30_000));
    const card = await screen.findByTestId('load-balancer-7');
    expect(card).toHaveTextContent('60% / 50% set');
    expect(card).toHaveTextContent('1 fell back');
  });

  it('stays on the current view while paused', async () => {
    renderPage();
    await screen.findByTestId('assistant-1');
    fireEvent.click(screen.getByRole('button', { name: 'Pause' }));
    act(() => vi.advanceTimersByTime(60_000));
    expect(screen.getByTestId('assistant-1')).toBeInTheDocument();
    expect(screen.getByText('Paused')).toBeInTheDocument();
  });

  it('uses the remembered time frame', async () => {
    localStorage.setItem('ai-monitor-minutes', '180');
    renderPage();
    await waitFor(() => expect(snapshot).toHaveBeenCalledWith(180));
  });

  it('rotates on the remembered refresh interval', async () => {
    localStorage.setItem('ai-monitor-slide-seconds', '60');
    renderPage();
    await screen.findByTestId('assistant-1');
    act(() => vi.advanceTimersByTime(30_000));
    expect(screen.getByTestId('assistant-1')).toBeInTheDocument();
    expect(screen.getByText('Load Balancers next in 30s')).toBeInTheDocument();
    act(() => vi.advanceTimersByTime(30_000));
    expect(await screen.findByTestId('load-balancer-7')).toBeInTheDocument();
  });

  it('shows an empty state when there are no assistants', async () => {
    snapshot.mockResolvedValue(data({ assistants: [] }));
    renderPage();
    expect(await screen.findByText('No AI assistants yet')).toBeInTheDocument();
  });
});
