import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { OwnerRoute } from '../OwnerRoute'

const auth = vi.hoisted(() => ({ value: {} as any }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth.value }))
vi.mock('sonner', () => ({ toast: { error: vi.fn() } }))

const ui = () => (
  <MemoryRouter>
    <OwnerRoute>
      <p>secret</p>
    </OwnerRoute>
  </MemoryRouter>
)

describe('OwnerRoute', () => {
  beforeEach(() => {
    auth.value = { user: null, isLoading: true }
  })

  it('does not throw when isLoading flips to false (hook order stable)', () => {
    const { rerender } = render(ui())
    expect(screen.getByText('Loading...')).toBeInTheDocument()
    auth.value = { user: { role: 'owner' }, isLoading: false }
    rerender(ui())
    expect(screen.getByText('secret')).toBeInTheDocument()
  })
})
