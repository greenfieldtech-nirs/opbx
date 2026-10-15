import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { QueryErrorState } from '../QueryErrorState';

describe('QueryErrorState', () => {
  it('shows the title and error message and calls onRetry', () => {
    const onRetry = vi.fn();
    render(<QueryErrorState title="Failed to load things" error={new Error('boom')} onRetry={onRetry} />);
    expect(screen.getByRole('alert')).toHaveTextContent('Failed to load things');
    fireEvent.click(screen.getByRole('button', { name: /retry/i }));
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('omits the retry button without onRetry', () => {
    render(<QueryErrorState />);
    expect(screen.queryByRole('button')).toBeNull();
  });
});
