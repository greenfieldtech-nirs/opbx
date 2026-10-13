import { describe, expect, it, vi } from 'vitest';
import { handleEmbedClose } from '../embedBus';

describe('handleEmbedClose', () => {
  it('closes the panel outside a call', () => {
    const emit = vi.fn();
    const close = vi.fn();
    handleEmbedClose(false, { emit }, close);
    expect(close).toHaveBeenCalledOnce();
    expect(emit).not.toHaveBeenCalled();
  });

  it('ignores close during a call and tells the host why', () => {
    const emit = vi.fn();
    const close = vi.fn();
    handleEmbedClose(true, { emit }, close);
    expect(close).not.toHaveBeenCalled();
    expect(emit).toHaveBeenCalledWith('close.rejected', { reason: 'call_in_progress' });
  });
});
