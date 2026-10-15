import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

describe('test runner', () => {
  it('renders React under jsdom', () => {
    render(<p>hello</p>)
    expect(screen.getByText('hello')).toBeInTheDocument()
  })
})
