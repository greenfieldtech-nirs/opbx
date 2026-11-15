import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import InstallGuide, { CodeBlock } from '../InstallGuide';

describe('InstallGuide page', () => {
  it('shows the install, everyday and upgrade commands', () => {
    render(<MemoryRouter><InstallGuide /></MemoryRouter>);
    expect(screen.getByRole('heading', { level: 1, name: /install & upgrade opbx/i })).toBeInTheDocument();
    for (const id of ['requirements', 'install', 'everyday', 'upgrade', 'change', 'troubleshooting']) {
      expect(document.getElementById(id)).not.toBeNull();
    }
    const text = document.body.textContent ?? '';
    for (const cmd of ['git clone https://github.com/greenfieldtech-nirs/opbx.git', './install.sh', './opbx.sh start', './opbx.sh stop',
      './scripts/update-compose.sh', './opbx.sh upgrade', './install.sh reconfigure', './install.sh reset']) {
      expect(text).toContain(cmd);
    }
  });
});

describe('CodeBlock', () => {
  it('copies the copy text (not the sample output) and confirms', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    render(<CodeBlock code={'$ ./install.sh\n◇  sample output'} copy="./install.sh" />);
    fireEvent.click(screen.getByRole('button', { name: /copy to clipboard/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Copied' })).toBeInTheDocument());
    expect(writeText).toHaveBeenCalledWith('./install.sh');
  });

  it('stays usable when the clipboard is blocked', async () => {
    Object.assign(navigator, { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } });
    render(<CodeBlock code="./opbx.sh start" />);
    fireEvent.click(screen.getByRole('button', { name: /copy to clipboard/i }));
    await waitFor(() => expect(screen.getByRole('button', { name: /copy to clipboard/i })).toBeInTheDocument());
    expect(screen.getByText('./opbx.sh start')).toBeInTheDocument();
  });
});
