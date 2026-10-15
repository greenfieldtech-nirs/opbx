import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import Announcements from '../Announcements';

let role = 'reporter';
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { role } }) }));
vi.mock('@/services/createResourceService', () => ({
  recordingsService: {
    getAll: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } }),
  },
}));

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <Announcements />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Announcements write controls', () => {
  it('are hidden from a Reporter', async () => {
    role = 'reporter';
    renderPage();
    await waitFor(() => expect(screen.getByText('Manage audio files for IVR and announcements')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /upload file/i })).toBeNull();
    expect(screen.queryByRole('button', { name: /remote url/i })).toBeNull();
  });

  it('are shown to a PBX Admin', async () => {
    role = 'pbx_admin';
    renderPage();
    expect((await screen.findAllByRole('button', { name: /upload file/i })).length).toBeGreaterThan(0);
  });
});
