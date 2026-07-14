import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Copy, Plus, RefreshCw, Trash2, Loader2 } from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { embedTokensService } from '@/services/embedTokens.service';
import type { EmbedIconPosition } from '@/types/embed.types';

interface EmbeddedDialerDialogProps {
  userId: string | number | null;
  userName?: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const ICON_POSITIONS: { value: EmbedIconPosition; label: string }[] = [
  { value: 'bottom-right', label: 'Bottom Right' },
  { value: 'bottom-left', label: 'Bottom Left' },
  { value: 'top-right', label: 'Top Right' },
  { value: 'top-left', label: 'Top Left' },
];

function copy(text: string, label: string): void {
  navigator.clipboard
    .writeText(text)
    .then(() => toast.success(`${label} copied to clipboard`))
    .catch(() => toast.error(`Failed to copy ${label.toLowerCase()}`));
}

export function EmbeddedDialerDialog({
  userId,
  userName,
  open,
  onOpenChange,
}: EmbeddedDialerDialogProps) {
  const queryClient = useQueryClient();

  const [domains, setDomains] = useState<string[]>([]);
  const [newDomain, setNewDomain] = useState('');
  const [iconPosition, setIconPosition] = useState<EmbedIconPosition>('bottom-right');
  const [iconColor, setIconColor] = useState('#007acc');
  // The one-time snippet revealed after a regenerate (never re-fetchable).
  const [snippet, setSnippet] = useState<string | null>(null);

  const { data: config, isLoading } = useQuery({
    queryKey: ['embed-token', userId],
    queryFn: () => embedTokensService.get(userId!),
    enabled: open && userId != null,
  });

  // Seed the form from the loaded config each time it arrives.
  useEffect(() => {
    if (config) {
      setDomains(config.allowed_domains ?? []);
      setIconPosition(config.icon_position ?? 'bottom-right');
      setIconColor(config.icon_background_color ?? '#007acc');
    }
  }, [config]);

  // Reset the one-time snippet whenever the dialog is (re)opened.
  useEffect(() => {
    if (open) setSnippet(null);
  }, [open, userId]);

  const saveMutation = useMutation({
    mutationFn: () =>
      embedTokensService.update(userId!, {
        allowed_domains: domains,
        icon_position: iconPosition,
        icon_background_color: iconColor,
      }),
    onSuccess: () => {
      toast.success('Embedded dialer settings saved');
      queryClient.invalidateQueries({ queryKey: ['embed-token', userId] });
    },
    onError: () => toast.error('Failed to save settings'),
  });

  const regenerateMutation = useMutation({
    mutationFn: () => embedTokensService.regenerate(userId!),
    onSuccess: (res) => {
      setSnippet(res.snippet);
      toast.success('New embed token generated. Old token is now revoked.');
      queryClient.invalidateQueries({ queryKey: ['embed-token', userId] });
    },
    onError: () => toast.error('Failed to regenerate token'),
  });

  const addDomain = () => {
    const d = newDomain.trim().toLowerCase();
    if (!d) return;
    if (domains.includes(d)) {
      toast.error('Domain already added');
      return;
    }
    setDomains((prev) => [...prev, d]);
    setNewDomain('');
  };

  const removeDomain = (d: string) => setDomains((prev) => prev.filter((x) => x !== d));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Embedded Dialer</DialogTitle>
          <DialogDescription>
            Embed a Web Phone for {userName ?? 'this user'} on any allowed website.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="flex items-center justify-center py-10">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : (
          <div className="space-y-5">
            {/* Allowed domains */}
            <div className="space-y-2">
              <Label>Allowed Domains</Label>
              <p className="text-xs text-muted-foreground">
                Only these domains may embed the dialer. Enter hostnames without
                the scheme (e.g. <code>crm.acme.com</code>).
              </p>
              <div className="flex gap-2">
                <Input
                  value={newDomain}
                  onChange={(e) => setNewDomain(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault();
                      addDomain();
                    }
                  }}
                  placeholder="crm.acme.com"
                />
                <Button type="button" variant="outline" onClick={addDomain}>
                  <Plus className="h-4 w-4" />
                </Button>
              </div>
              {domains.length > 0 ? (
                <ul className="flex flex-col divide-y rounded-md border">
                  {domains.map((d) => (
                    <li key={d} className="flex items-center justify-between px-3 py-2 text-sm">
                      <code>{d}</code>
                      <button
                        type="button"
                        onClick={() => removeDomain(d)}
                        className="text-muted-foreground hover:text-destructive"
                        aria-label={`Remove ${d}`}
                      >
                        <Trash2 className="h-4 w-4" />
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-xs text-muted-foreground italic">
                  No domains yet — the dialer cannot be embedded until you add one.
                </p>
              )}
            </div>

            {/* Icon position + color */}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label>Icon Position</Label>
                <Select
                  value={iconPosition}
                  onValueChange={(v) => setIconPosition(v as EmbedIconPosition)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {ICON_POSITIONS.map((p) => (
                      <SelectItem key={p.value} value={p.value}>
                        {p.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="space-y-2">
                <Label htmlFor="embed-color">Icon Color</Label>
                <div className="flex items-center gap-2">
                  <input
                    id="embed-color"
                    type="color"
                    value={iconColor}
                    onChange={(e) => setIconColor(e.target.value)}
                    className="h-9 w-12 cursor-pointer rounded border bg-background p-1"
                    aria-label="Icon background color"
                  />
                  <Input
                    value={iconColor}
                    onChange={(e) => setIconColor(e.target.value)}
                    className="font-mono"
                  />
                </div>
              </div>
            </div>

            {/* One-time snippet reveal */}
            {snippet && (
              <div className="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3">
                <div className="flex items-center justify-between">
                  <Label className="text-amber-900">
                    Installation Snippet (shown once)
                  </Label>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    onClick={() => copy(snippet, 'Snippet')}
                  >
                    <Copy className="mr-2 h-3.5 w-3.5" />
                    Copy
                  </Button>
                </div>
                <Textarea
                  readOnly
                  value={snippet}
                  onFocus={(e) => e.currentTarget.select()}
                  className="h-40 font-mono text-xs"
                />
                <p className="text-xs text-amber-800">
                  Copy this now. The token is not stored in plaintext and cannot be
                  shown again — regenerate to get a new one (which revokes this one).
                </p>
              </div>
            )}
          </div>
        )}

        <DialogFooter className="gap-2 sm:justify-between">
          <Button
            type="button"
            variant="outline"
            onClick={() => regenerateMutation.mutate()}
            disabled={regenerateMutation.isPending || isLoading}
          >
            <RefreshCw
              className={`mr-2 h-4 w-4 ${regenerateMutation.isPending ? 'animate-spin' : ''}`}
            />
            Regenerate Token
          </Button>
          <Button
            type="button"
            onClick={() => saveMutation.mutate()}
            disabled={saveMutation.isPending || isLoading}
          >
            {saveMutation.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            Save Settings
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default EmbeddedDialerDialog;
