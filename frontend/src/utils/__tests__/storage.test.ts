import { beforeEach, describe, expect, it } from 'vitest'
import { storage } from '@/utils/storage'

describe('storage', () => {
  beforeEach(() => localStorage.clear())

  it('returns a stored user', () => {
    storage.setUser({ id: 1 })
    expect(storage.getUser<{ id: number }>()).toEqual({ id: 1 })
  })

  it('drops a corrupt user value instead of throwing', () => {
    localStorage.setItem('opbx_user', '{not json')
    expect(storage.getUser()).toBeNull()
    expect(localStorage.getItem('opbx_user')).toBeNull()
  })

  it('drops a corrupt operate-as value', () => {
    localStorage.setItem('opbx_operate_as', 'nope')
    expect(storage.getOperateAsOrg()).toBeNull()
    expect(localStorage.getItem('opbx_operate_as')).toBeNull()
  })
})
