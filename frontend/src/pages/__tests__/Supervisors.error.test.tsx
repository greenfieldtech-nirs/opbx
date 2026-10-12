import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import Supervisors from '../Supervisors';

vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { role: 'owner' } }) }));
vi.mock('@/services/createResourceService', () => ({
  usersService: { getAll: vi.fn().mockRejectedValue(new Error('network down')) },
}));
vi.mock('@/services/supervisorAssignments.service', () => ({ getSupervisorAssignments: vi.fn() }));

describe('Supervisors page', () => {
  it('shows the shared error state with a retry action when the users query fails', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <Supervisors />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Failed to load supervisors'));
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });
});
