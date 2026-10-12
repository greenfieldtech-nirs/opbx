import { describe, expect, it, vi } from 'vitest'

const publicGet = vi.hoisted(() => vi.fn())
const authGet = vi.hoisted(() => vi.fn())
vi.mock('@/services/api', () => ({ default: { get: authGet }, publicApi: { get: publicGet } }))

import { publicQueuesService } from '../publicQueues.service'

describe('publicQueuesService', () => {
  it('uses the interceptor-free publicApi client, never the authenticated one', async () => {
    publicGet.mockResolvedValue({ data: { data: [] } })
    await publicQueuesService.queues('tok')
    await publicQueuesService.live('tok', 1)
    await publicQueuesService.stats('tok', 1, '2026-01-01T00:00:00Z')
    expect(publicGet).toHaveBeenCalledTimes(3)
    expect(authGet).not.toHaveBeenCalled()
  })
})
