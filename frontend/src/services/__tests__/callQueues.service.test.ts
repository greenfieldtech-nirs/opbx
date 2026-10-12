import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.hoisted(() => vi.fn())
vi.mock('@/services/api', () => ({ default: { get } }))

import { queueReportsService } from '../callQueues.service'

describe('queueReportsService.exportCsv', () => {
  beforeEach(() => {
    get.mockReset()
    get.mockResolvedValue({ data: new Blob(['a,b']) })
    URL.createObjectURL = vi.fn(() => 'blob:x')
    URL.revokeObjectURL = vi.fn()
    HTMLAnchorElement.prototype.click = vi.fn()
  })

  it('requests the export through the authenticated client as a blob', async () => {
    await queueReportsService.exportCsv({ queue_id: 3 })
    expect(get).toHaveBeenCalledWith('/queue-calls/export', { params: { queue_id: 3 }, responseType: 'blob' })
    expect(URL.createObjectURL).toHaveBeenCalled()
    expect(HTMLAnchorElement.prototype.click).toHaveBeenCalled()
  })
})
