import { beforeEach, describe, expect, it } from 'vitest'
import { storage } from '@/utils/storage'

describe('storage', () => {
  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
  })

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

  it('keeps a "Remember me" session in localStorage', () => {
    storage.setToken('remembered', true)
    storage.setUser({ id: 1 })
    expect(localStorage.getItem('opbx_token')).toBe('remembered')
    expect(localStorage.getItem('opbx_user')).toBe('{"id":1}')
    expect(sessionStorage.getItem('opbx_token')).toBeNull()
  })

  it('keeps a normal session in sessionStorage only', () => {
    storage.setToken('old', true)
    storage.setToken('session', false)
    storage.setUser({ id: 2 })
    expect(storage.getToken()).toBe('session')
    expect(storage.getUser<{ id: number }>()).toEqual({ id: 2 })
    expect(localStorage.getItem('opbx_token')).toBeNull()
    expect(localStorage.getItem('opbx_user')).toBeNull()
  })
})
