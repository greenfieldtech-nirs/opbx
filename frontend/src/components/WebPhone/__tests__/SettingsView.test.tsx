import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, vi } from 'vitest';
import { SettingsView } from '../SettingsView';
import { WebPhoneTabs } from '../WebPhoneTabs';
import { DEFAULT_WEBPHONE_SETTINGS } from '@/types/webPhone.types';

const renderView = (saveFn = vi.fn(async (s) => s), onSaved = vi.fn()) => {
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SettingsView settings={DEFAULT_WEBPHONE_SETTINGS} onSaved={onSaved} saveFn={saveFn} />
    </QueryClientProvider>,
  );
  return { saveFn, onSaved };
};

describe('Web Phone Settings tab', () => {
  it('shows the defaults and keeps Save disabled until something changes', () => {
    renderView();
    expect(screen.getByRole('switch', { name: 'Auto-Answer' })).not.toBeChecked();
    expect(screen.getByRole('switch', { name: 'Keep-Alive' })).toBeChecked();
    expect(screen.getByRole('switch', { name: 'Refresh on hangup' })).toBeChecked();
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();

    fireEvent.click(screen.getByRole('switch', { name: 'Auto-Answer' }));
    expect(screen.getByRole('button', { name: 'Save' })).toBeEnabled();
    fireEvent.click(screen.getByRole('switch', { name: 'Auto-Answer' }));
    expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
  });

  it('saves the changed settings and applies them', async () => {
    const { saveFn, onSaved } = renderView();
    fireEvent.click(screen.getByRole('switch', { name: 'Auto-Answer' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    const expected = { auto_answer: true, keep_alive: true, refresh_on_hangup: true };
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(expected));
    expect(saveFn.mock.calls[0][0]).toEqual(expected);
  });

  it('shows the server error and keeps the change when saving fails', async () => {
    const saveFn = vi.fn(async () => {
      throw { response: { data: { error: { message: 'Cloudonix did not accept the settings. Try again shortly.' } } } };
    });
    renderView(saveFn);
    fireEvent.click(screen.getByRole('switch', { name: 'Keep-Alive' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Cloudonix did not accept the settings');
    expect(screen.getByRole('switch', { name: 'Keep-Alive' })).not.toBeChecked();
  });

  it('is not offered in the embedded dialer', () => {
    render(<WebPhoneTabs active="dialer" onChange={() => {}} showSettings={false} />);
    expect(screen.queryByRole('tab', { name: 'Settings' })).not.toBeInTheDocument();
    expect(screen.getAllByRole('tab')).toHaveLength(2);
  });
});
