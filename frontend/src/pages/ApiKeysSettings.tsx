/**
 * API Keys Settings (owner-only)
 *
 * Manage scoped API keys: create (with one-time plaintext reveal),
 * view, and revoke. Route is wrapped in <OwnerRoute>; the backend also
 * 403s non-owners.
 */

import { useState } from 'react';
import { Key, Plus, Copy, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { EmptyState } from '@/components/design-system/EmptyState';
import { ApiKeyPermissionBuilder } from '@/components/settings/ApiKeyPermissionBuilder';
import { useAuth } from '@/hooks/useAuth';
import {
  useApiKeys,
  useGrantableResources,
  useCreateApiKey,
  useRevokeApiKey,
} from '@/hooks/useApiKeys';
import type { ApiKey, ApiKeyPermission } from '@/services/apiKeys.service';

function formatLastUsed(value: string | null): string {
  if (!value) return 'Never';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return 'Never';
  return date.toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

export default function ApiKeysSettings() {
  const { user } = useAuth();
  const canCreate = user?.role === 'owner';

  const { data: apiKeys = [], isLoading } = useApiKeys();
  const { data: resources = [] } = useGrantableResources();
  const createMutation = useCreateApiKey();
  const revokeMutation = useRevokeApiKey();

  // Create dialog state
  const [createOpen, setCreateOpen] = useState(false);
  const [name, setName] = useState('');
  const [permissions, setPermissions] = useState<ApiKeyPermission[]>([]);

  // Reveal dialog state (holds the one-time plaintext key)
  const [revealedKey, setRevealedKey] = useState<string | null>(null);

  // Revoke confirmation state
  const [keyToRevoke, setKeyToRevoke] = useState<ApiKey | null>(null);

  const canSubmit = name.trim().length > 0 && permissions.length > 0;

  const resetCreateForm = () => {
    setName('');
    setPermissions([]);
  };

  const handleCreate = () => {
    if (!canSubmit) return;
    createMutation.mutate(
      { name: name.trim(), permissions },
      {
        onSuccess: (result) => {
          setCreateOpen(false);
          resetCreateForm();
          setRevealedKey(result.key);
        },
      }
    );
  };

  const handleCopy = async () => {
    if (!revealedKey) return;
    await navigator.clipboard.writeText(revealedKey);
    toast.success('Copied');
  };

  const handleRevoke = () => {
    if (!keyToRevoke) return;
    revokeMutation.mutate(keyToRevoke.id, {
      onSuccess: () => setKeyToRevoke(null),
    });
  };

  const openCreate = () => {
    resetCreateForm();
    setCreateOpen(true);
  };

  return (
    <div className="p-6">
      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4">
          <div>
            <CardTitle className="flex items-center gap-2">
              <Key className="h-5 w-5" />
              API Keys
            </CardTitle>
            <CardDescription>
              Create and manage scoped API keys for programmatic access.
            </CardDescription>
          </div>
          {canCreate && apiKeys.length > 0 && (
            <Button onClick={openCreate}>
              <Plus className="mr-2 h-4 w-4" />
              Create API Key
            </Button>
          )}
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Loading API keys…
            </p>
          ) : apiKeys.length === 0 ? (
            <EmptyState
              icon={Key}
              title="No API keys found"
              description="Get started by creating your first API key"
              action={
                canCreate
                  ? { label: 'Create API Key', onClick: openCreate }
                  : undefined
              }
            />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Name</TableHead>
                  <TableHead>Permissions</TableHead>
                  <TableHead>Last used</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {apiKeys.map((key) => {
                  const revoked = key.revoked_at !== null;
                  return (
                    <TableRow key={key.id} className={revoked ? 'opacity-60' : ''}>
                      <TableCell className="font-medium">{key.name}</TableCell>
                      <TableCell>
                        {key.permissions.length === 0 ? (
                          <span className="text-sm text-muted-foreground">
                            No access
                          </span>
                        ) : (
                          <div className="flex flex-wrap gap-1">
                            {key.permissions.map((p) => (
                              <Badge
                                key={`${p.resource}:${p.level}`}
                                variant="secondary"
                              >
                                {p.resource}:{p.level}
                              </Badge>
                            ))}
                          </div>
                        )}
                      </TableCell>
                      <TableCell>{formatLastUsed(key.last_used_at)}</TableCell>
                      <TableCell>
                        {revoked ? (
                          <Badge variant="destructive">Revoked</Badge>
                        ) : (
                          <Badge variant="default">Active</Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        {!revoked && canCreate && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setKeyToRevoke(key)}
                          >
                            <Trash2 className="mr-1 h-4 w-4" />
                            Revoke
                          </Button>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {/* Create dialog */}
      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent className="max-w-lg">
          <DialogHeader>
            <DialogTitle>Create API Key</DialogTitle>
            <DialogDescription>
              Give the key a name and grant it access to specific resources.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="api-key-name">Name</Label>
              <Input
                id="api-key-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. CI pipeline"
              />
            </div>
            <ApiKeyPermissionBuilder
              resources={resources}
              value={permissions}
              onChange={setPermissions}
            />
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              type="button"
              onClick={() => setCreateOpen(false)}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={handleCreate}
              disabled={!canSubmit || createMutation.isPending}
            >
              {createMutation.isPending ? 'Creating…' : 'Create'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* One-time reveal dialog — not dismissible by overlay/esc */}
      <Dialog open={revealedKey !== null}>
        <DialogContent
          className="max-w-lg"
          onEscapeKeyDown={(e) => e.preventDefault()}
          onInteractOutside={(e) => e.preventDefault()}
          onPointerDownOutside={(e) => e.preventDefault()}
        >
          <DialogHeader>
            <DialogTitle>API Key Created</DialogTitle>
            <DialogDescription>
              This is the only time the key will be shown. Store it securely —
              you won't be able to see it again.
            </DialogDescription>
          </DialogHeader>
          <div className="flex items-center gap-2">
            <code className="flex-1 overflow-x-auto rounded-md bg-muted px-3 py-2 font-mono text-sm">
              {revealedKey}
            </code>
            <Button type="button" variant="outline" size="icon" onClick={handleCopy}>
              <Copy className="h-4 w-4" />
              <span className="sr-only">Copy</span>
            </Button>
          </div>
          <DialogFooter>
            <Button type="button" onClick={() => setRevealedKey(null)}>
              I've copied it
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Revoke confirmation */}
      <AlertDialog
        open={keyToRevoke !== null}
        onOpenChange={(open) => !open && setKeyToRevoke(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Revoke API key?</AlertDialogTitle>
            <AlertDialogDescription>
              Revoking <strong>{keyToRevoke?.name}</strong> immediately disables
              it. This cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleRevoke}
              disabled={revokeMutation.isPending}
            >
              {revokeMutation.isPending ? 'Revoking…' : 'Revoke'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
