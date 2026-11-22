import { useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { getWebPhoneDirectory, type WebPhoneDirectoryResponse } from '@/services/webPhone.service';
import type { WebPhoneDirectoryEntry } from '@/types/webPhone.types';

// An extension or a phone number; mirrors the server's validation.
const DESTINATION_PATTERN = /^\+?\d{1,20}$/;
const MAX_SUGGESTIONS = 4;

interface TransferPanelProps {
  /** Resolves once the call is transferred; rejects (with the server's message) when it is not. */
  onTransfer: (destination: string) => Promise<void>;
  onCancel: () => void;
  directoryQueryFn?: () => Promise<WebPhoneDirectoryResponse>;
}

/** Extensions whose number starts with, or whose name contains, what was typed. */
export function matchDirectory(entries: WebPhoneDirectoryEntry[], query: string): WebPhoneDirectoryEntry[] {
  const q = query.trim().toLowerCase();
  if (!q) return entries.slice(0, MAX_SUGGESTIONS);
  return entries
    .filter((e) => e.extension_number.startsWith(q) || (e.name ?? '').toLowerCase().includes(q))
    .slice(0, MAX_SUGGESTIONS);
}

function errorMessage(error: any): string {
  return error?.response?.data?.message ?? error?.message ?? 'The transfer failed.';
}

/**
 * Blind transfer: type an extension or number, or pick an extension from the suggestions.
 */
export function TransferPanel({ onTransfer, onCancel, directoryQueryFn }: TransferPanelProps) {
  const [destination, setDestination] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data } = useQuery({
    queryKey: ['webphone-directory'],
    queryFn: directoryQueryFn ?? getWebPhoneDirectory,
    staleTime: 60_000,
  });
  const suggestions = useMemo(() => matchDirectory(data?.data ?? [], destination), [data, destination]);
  const valid = DESTINATION_PATTERN.test(destination.trim());

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    if (!valid || pending) return;
    setPending(true);
    setError(null);
    try {
      await onTransfer(destination.trim());
    } catch (e) {
      setError(errorMessage(e));
      setPending(false);
    }
  };

  return (
    <form onSubmit={submit} className="flex w-full max-w-[232px] flex-col gap-2" aria-label="Transfer call">
      <label htmlFor="webphone-transfer-destination" className="text-sm font-medium">
        Transfer to
      </label>
      <Input
        id="webphone-transfer-destination"
        autoFocus
        autoComplete="off"
        inputMode="tel"
        placeholder="Extension or number"
        value={destination}
        onChange={(e) => {
          setDestination(e.target.value);
          setError(null);
        }}
        disabled={pending}
      />
      {suggestions.length > 0 && (
        <ul className="flex flex-col rounded-md border" aria-label="Extensions">
          {suggestions.map((entry) => (
            <li key={entry.extension_number}>
              <button
                type="button"
                onClick={() => setDestination(entry.extension_number)}
                disabled={pending}
                className="flex h-9 w-full items-center gap-2 px-3 text-left text-sm hover:bg-muted"
              >
                <span className="font-medium tabular-nums">{entry.extension_number}</span>
                {entry.name && <span className="truncate text-muted-foreground">{entry.name}</span>}
              </button>
            </li>
          ))}
        </ul>
      )}
      {error && (
        <p className="text-xs text-destructive" role="alert">
          {error}
        </p>
      )}
      <div className="flex gap-2">
        <Button type="button" variant="outline" size="sm" className="flex-1" onClick={onCancel} disabled={pending}>
          Cancel
        </Button>
        <Button type="submit" size="sm" className="flex-1" disabled={!valid || pending}>
          {pending ? <Loader2 className="h-4 w-4 animate-spin" aria-label="Transferring" /> : 'Transfer'}
        </Button>
      </div>
    </form>
  );
}
