import { useEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { saveWebPhoneSettings } from '@/services/webPhone.service';
import type { WebPhoneSettings } from '@/types/webPhone.types';

const OPTIONS: { key: keyof WebPhoneSettings; label: string; hint: string }[] = [
  { key: 'auto_answer', label: 'Auto-Answer', hint: 'Answer an incoming call after it rings for 2 seconds.' },
  { key: 'keep_alive', label: 'Keep-Alive', hint: 'Re-register with the server every 60 seconds.' },
  { key: 'refresh_on_hangup', label: 'Refresh on hangup', hint: 'Re-register after each call ends.' },
];

interface SettingsViewProps {
  settings: WebPhoneSettings;
  onSaved: (settings: WebPhoneSettings) => void;
  /** Tests inject the save call. */
  saveFn?: (settings: WebPhoneSettings) => Promise<WebPhoneSettings>;
}

function serverMessage(error: unknown): string {
  const message = (error as { response?: { data?: { error?: { message?: string }; message?: string } } })?.response?.data;
  return message?.error?.message ?? message?.message ?? 'The settings could not be saved. Try again.';
}

// Web Phone Settings tab: changes are kept here until Save stores them in the Cloudonix subscriber profile.
export function SettingsView({ settings, onSaved, saveFn = saveWebPhoneSettings }: SettingsViewProps) {
  const [draft, setDraft] = useState(settings);
  const [saved, setSaved] = useState(false);

  useEffect(() => setDraft(settings), [settings]);

  const save = useMutation({
    mutationFn: saveFn,
    onSuccess: (stored) => {
      onSaved(stored);
      setSaved(true);
    },
  });

  const dirty = OPTIONS.some(({ key }) => draft[key] !== settings[key]);

  return (
    <div className="flex flex-col gap-4">
      {OPTIONS.map(({ key, label, hint }) => (
        <label key={key} className="flex items-start justify-between gap-4">
          <span>
            <span className="block text-sm font-medium text-foreground">{label}</span>
            <span className="block text-xs text-muted-foreground">{hint}</span>
          </span>
          <Switch
            checked={draft[key]}
            onCheckedChange={(checked) => {
              setDraft((d) => ({ ...d, [key]: checked }));
              setSaved(false);
            }}
            aria-label={label}
          />
        </label>
      ))}

      <Button onClick={() => save.mutate(draft)} disabled={!dirty || save.isPending}>
        {save.isPending ? 'Saving...' : 'Save'}
      </Button>

      {save.isError && dirty && (
        <p role="alert" className="text-sm text-destructive">
          {serverMessage(save.error)}
        </p>
      )}
      {saved && !dirty && (
        <p role="status" className="text-sm text-muted-foreground">
          Saved.
        </p>
      )}
    </div>
  );
}

export default SettingsView;
