import { render, screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import TermsOfUse, { SECTIONS, TERMS_UPDATED } from '../TermsOfUse';

const renderPage = () => render(<MemoryRouter><TermsOfUse /></MemoryRouter>);

describe('TermsOfUse page', () => {
  it('has a dated title and a contents list linking to every numbered section', () => {
    renderPage();
    expect(screen.getByRole('heading', { level: 1, name: 'Terms of Use' })).toBeInTheDocument();
    expect(screen.getByText(new RegExp(`Last updated ${TERMS_UPDATED}`))).toBeInTheDocument();
    const toc = screen.getByRole('navigation', { name: 'Sections' });
    SECTIONS.forEach((s, i) => {
      expect(within(toc).getByRole('link', { name: `${i + 1}. ${s.title}` })).toHaveAttribute('href', `#${s.id}`);
      expect(document.getElementById(s.id)).toHaveAccessibleName(`${i + 1}. ${s.title}`);
    });
  });

  it('covers both the cloud application and the open-source software, and the key protections', () => {
    renderPage();
    const text = document.body.textContent ?? '';
    for (const phrase of [
      'Cloud Application', 'MIT License', 'sole risk and is your sole responsibility', '"AS IS" AND "AS AVAILABLE"',
      'IN NO EVENT SHALL ANY RELATED PARTY BE LIABLE', 'defend, indemnify and hold harmless the Related Parties',
      'No emergency use', 'OPBX Project', 'Cloudonix',
    ]) {
      expect(text).toContain(phrase);
    }
  });
});
