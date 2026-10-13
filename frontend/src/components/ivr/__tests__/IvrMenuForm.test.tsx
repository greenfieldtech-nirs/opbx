import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { EMPTY_IVR_FORM, IvrMenuForm, ivrMenuToFormValues } from '../IvrMenuForm';
import type { IvrMenu } from '@/types/api.types';
import type { IvrMenuFormValues } from '@/schemas/ivrMenu';

// The real picker fetches destinations; the form only needs it to call onChange.
vi.mock('@/components/destinations', () => ({
  DestinationTypeAndSelector: () => <div data-testid="destination-picker" />,
}));
vi.mock('@/components/ui/combobox', () => ({ Combobox: () => <div /> }));

vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} });
Element.prototype.scrollIntoView = vi.fn();
Element.prototype.hasPointerCapture = vi.fn();

const renderForm = (values: Partial<IvrMenuFormValues>, mode: 'create' | 'edit' = 'create') => {
  const onSubmit = vi.fn();
  render(
    <IvrMenuForm
      mode={mode}
      defaultValues={{ ...EMPTY_IVR_FORM, ...values }}
      onSubmit={onSubmit}
      onCancel={vi.fn()}
      submitLabel="Save"
      voices={[]}
      voiceFilters={{ languages: [], genders: [], providers: [] }}
      onRefreshVoices={vi.fn()}
    />,
  );
  return onSubmit;
};

const option = { input_digits: '1', description: '', destination_type: 'extension' as const, destination_id: '5' };
const submit = () => fireEvent.click(screen.getByRole('button', { name: 'Save' }));

describe('IvrMenuForm', () => {
  it('shows every invalid field under itself at once and blocks submit', async () => {
    const onSubmit = renderForm({});
    submit();

    expect(await screen.findByText('Name is required')).toBeInTheDocument();
    // empty audio source (Audio tab) is shown, and the Menu Options tab is flagged
    expect(screen.getByText(/Choose an audio source/)).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Menu Options/ })).toHaveTextContent('(!)');
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('flags a missing option list on the options tab', async () => {
    renderForm({ name: 'Main', useTTS: true, tts_text: 'Hello' });
    submit();
    expect(await screen.findByText('At least one menu option is required')).toBeInTheDocument();
  });

  it('opens the collapsed Menu Settings when one of its fields is invalid', async () => {
    renderForm({ name: 'Main', useTTS: true, tts_text: 'Hello', options: [option], max_turns: 0 });
    expect(screen.queryByText('Maximum Turns')).not.toBeInTheDocument();
    submit();
    // The section opens on its own and the invalid field shows its message.
    expect(await screen.findByText('Maximum Turns')).toBeInTheDocument();
    expect(screen.getByLabelText('Maximum Turns')).toHaveAttribute('aria-invalid', 'true');
  });

  it('lists options as rows of one table and deletes a row with its icon button', async () => {
    renderForm({ name: 'Main', useTTS: true, tts_text: 'Hello', options: [option, { ...option, input_digits: '2' }] });
    fireEvent.mouseDown(screen.getByRole('tab', { name: /Menu Options/ }));
    fireEvent.click(screen.getByRole('tab', { name: /Menu Options/ }));
    expect(await screen.findAllByRole('table')).toHaveLength(1);
    expect(screen.getByLabelText('Option 2 digits')).toHaveValue('2');
    fireEvent.click(screen.getByRole('button', { name: 'Delete option 1' }));
    await waitFor(() => expect(screen.queryByLabelText('Option 2 digits')).not.toBeInTheDocument());
    expect(screen.getByLabelText('Option 1 digits')).toHaveValue('2');
  });

  it('shows the TTS length error and a live counter', async () => {
    renderForm({ name: 'Main', useTTS: true, tts_text: 'x', options: [option] });
    fireEvent.change(screen.getByLabelText('Text to Speak'), { target: { value: 'a'.repeat(1001) } });

    expect(await screen.findByText(/may not exceed 1000 characters/)).toBeInTheDocument();
    expect(screen.getByText(/1001\/1000 characters/)).toBeInTheDocument();
    submit();
    await waitFor(() => expect(screen.getByText(/may not exceed 1000/)).toBeInTheDocument());
  });

  it('shows the option error when an option has no digits', async () => {
    const onSubmit = renderForm({ name: 'Main', useTTS: true, tts_text: 'Hello', options: [{ ...option, input_digits: '' }] });
    submit();
    expect(await screen.findByText('Each menu option needs digits')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('submits the API payload when valid', async () => {
    const onSubmit = renderForm({ name: ' Main ', useTTS: true, tts_text: 'Hello', options: [option] });
    submit();
    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
    expect(onSubmit.mock.calls[0][0]).toMatchObject({
      name: 'Main',
      tts_text: 'Hello',
      audio_file_path: undefined,
      options: [{ input_digits: '1', destination_id: '5', priority: 1 }],
    });
  });

  it('pre-fills the edit form from an existing menu', () => {
    const menu = {
      id: '7', name: 'Support', status: 'inactive', tts_text: 'Welcome', tts_voice: 'v1',
      max_timeout: 5, inter_digit_timeout: 2, max_turns: 3,
      failover_destination_type: 'hangup',
      options: [{ id: 1, input_digits: '2', destination_type: 'ring_group', destination_id: 9, priority: 1 }],
    } as unknown as IvrMenu;
    renderForm(ivrMenuToFormValues(menu), 'edit');

    expect(screen.getByLabelText('Name *')).toHaveValue('Support');
    expect(screen.getByLabelText('Text to Speak')).toHaveValue('Welcome');
    expect(screen.getByText(/7\/1000 characters/)).toBeInTheDocument();
  });
});
