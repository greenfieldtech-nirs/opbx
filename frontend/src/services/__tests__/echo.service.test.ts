import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('laravel-echo', () => ({ default: class {} }));
vi.mock('pusher-js', () => ({ default: class {} }));
vi.mock('@/services/api', () => ({ default: { post: vi.fn() } }));

import api from '@/services/api';
import { channelAuthorizer, resolveWsHost } from '../echo.service';

describe('resolveWsHost', () => {
  it('in development follows the page host when the configured host is localhost or unset', () => {
    expect(resolveWsHost('localhost', 'dev-box.tailnet.ts.net', true)).toBe('dev-box.tailnet.ts.net');
    expect(resolveWsHost(undefined, '100.64.0.7', true)).toBe('100.64.0.7');
    expect(resolveWsHost('localhost', 'localhost', true)).toBe('localhost');
  });

  it('keeps an explicitly configured host, and always in production builds', () => {
    expect(resolveWsHost('pbx.example.com', 'dev-box.tailnet.ts.net', true)).toBe('pbx.example.com');
    expect(resolveWsHost('localhost', 'dev-box.tailnet.ts.net', false)).toBe('localhost');
    expect(resolveWsHost('pbx.example.com', 'pbx.example.com', false)).toBe('pbx.example.com');
  });
});

describe('channelAuthorizer', () => {
  beforeEach(() => vi.clearAllMocks());

  it('authorizes through the shared api client (current token and operate-as header)', async () => {
    vi.mocked(api.post).mockResolvedValue({ data: { auth: 'k:sig' } });
    const callback = vi.fn();

    channelAuthorizer({ name: 'presence-org.7' }).authorize('123.456', callback);
    await vi.waitFor(() => expect(callback).toHaveBeenCalled());

    expect(api.post).toHaveBeenCalledWith('/broadcasting/auth', { socket_id: '123.456', channel_name: 'presence-org.7' });
    expect(callback).toHaveBeenCalledWith(null, { auth: 'k:sig' });
  });

  it('reports an authorization failure through the callback', async () => {
    vi.mocked(api.post).mockRejectedValue(new Error('401'));
    const callback = vi.fn();

    channelAuthorizer({ name: 'presence-org.7' }).authorize('1.2', callback);
    await vi.waitFor(() => expect(callback).toHaveBeenCalled());

    expect(callback.mock.calls[0][0]).toBeInstanceOf(Error);
  });
});
