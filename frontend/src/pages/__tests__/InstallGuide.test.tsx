import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import InstallGuide, { CodeBlock, DEV_PORTS } from '../InstallGuide';

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

describe('development ports table', () => {
  it('lists every port with its service and .env variable', () => {
    render(<MemoryRouter><InstallGuide /></MemoryRouter>);
    const table = screen.getByRole('table', { name: /local ports/i });
    for (const [port, service, , variable] of DEV_PORTS) {
      const row = within(table).getByText(String(port)).closest('tr') as HTMLElement;
      expect(row).toHaveTextContent(service);
      expect(row).toHaveTextContent(variable);
    }
  });

  // The repo root (with the overlay) is not mounted in the Vite dev container: reported as skipped there.
  const overlay = resolve(process.cwd(), '../docker-compose.ports.yml'); // tests run from frontend/
  it.skipIf(!existsSync(overlay))('matches docker-compose.ports.yml (port and variable)', () => {
    const yml = readFileSync(overlay, 'utf8');
    const fromYml = [...yml.matchAll(/\$\{(\w+):-(\d+)\}/g)].map(([, v, p]) => `${p} ${v}`).sort();
    expect(DEV_PORTS.map(([p, , , v]) => `${p} ${v}`).sort()).toEqual(fromYml);
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
