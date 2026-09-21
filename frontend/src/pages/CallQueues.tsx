/**
 * Call Queues Management Page
 * CRUD with agents, strategy, MOH, position announcements, and fallback destination.
 */

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import { Plus, Users, ListMusic } from 'lucide-react';

import {
  callQueuesService,
  type CallQueue,
  type CallQueuePayload,
  type CallQueueStrategy,
} from '@/services/callQueues.service';
import { recordingsService, usersService } from '@/services/createResourceService';
import { cloudonixService } from '@/services/cloudonix.service';
import { useAuth } from '@/hooks/useAuth';
import { StandardDataTable, Column, EmptyState, CallQueueStrategySelector } from '@/components/design-system';
import { DestinationTypeAndSelector } from '@/components/destinations';
import type { DestinationType } from '@/components/destinations/types/destination.types';
import { getErrorMessage } from '@/types/api';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

const AGENT_RING_TIMEOUTS = [15, 20, 25, 30, 35, 40, 45, 50, 55, 60];
const MAX_WAIT_OPTIONS = [30, 60, 90, 120, 150, 180, 210, 240, 270, 300];
const WRAP_UP_OPTIONS = [15, 30, 45, 60, 75, 90, 105, 120];
const ANNOUNCE_INTERVALS = [15, 30, 60, 120];

interface QueueFormState {
  name: string;
  description: string;
  strategy: CallQueueStrategy;
  agent_ring_timeout: number;
  max_wait_seconds: number;
  wrap_up_seconds: number;
  announce_position: boolean;
  announce_position_timeout: number;
  announce_position_language: string;
  moh_recording_id: string;
  fallback_action: string;
  fallback_extension_id: string;
  fallback_ring_group_id: string;
  fallback_ivr_menu_id: string;
  fallback_ai_assistant_id: string;
  fallback_ai_load_balancer_id: string;
  status: 'active' | 'inactive';
  agents: number[];
}

const emptyForm: QueueFormState = {
  name: '',
  description: '',
  strategy: 'ring_all',
  agent_ring_timeout: 20,
  max_wait_seconds: 300,
  wrap_up_seconds: 15,
  announce_position: false,
  announce_position_timeout: 60,
  announce_position_language: '',
  moh_recording_id: '',
  fallback_action: 'hangup',
  fallback_extension_id: '',
  fallback_ring_group_id: '',
  fallback_ivr_menu_id: '',
  fallback_ai_assistant_id: '',
  fallback_ai_load_balancer_id: '',
  status: 'active',
  agents: [],
};

function NumberSelect({
  value,
  options,
  onChange,
  placeholder,
}: {
  value: number;
  options: number[];
  onChange: (value: number) => void;
  placeholder?: string;
}) {
  return (
    <Select value={String(value)} onValueChange={(v) => onChange(Number(v))}>
      <SelectTrigger>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option} value={String(option)}>
            {option} seconds
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

export default function CallQueues() {
  const { user } = useAuth();
  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<CallQueue | null>(null);
  const [form, setForm] = useState<QueueFormState>(emptyForm);

  const { data, isLoading } = useQuery({
    queryKey: ['call-queues'],
    queryFn: () => callQueuesService.getAll(),
  });

  const { data: recordingsData } = useQuery({
    queryKey: ['recordings', 'moh'],
    queryFn: () => recordingsService.getAll({ moh: 1, per_page: 100 }),
  });

  // TTS languages for position announcements (same source as IVR Menus).
  const { data: voicesData } = useQuery({
    queryKey: ['cloudonix-voices'],
    queryFn: () => cloudonixService.getVoices(),
    staleTime: 30 * 24 * 60 * 60 * 1000,
  });
  const languages = voicesData?.filters?.languages ?? [];

  // Agents are users with an assigned extension.
  const { data: usersData } = useQuery({
    queryKey: ['users', 'queue-agents'],
    queryFn: () => usersService.getAll({ per_page: 100 }),
    enabled: canManage,
  });

  const agentUsers = (usersData?.data ?? []).filter((u: any) => u.extension?.extension_number);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['call-queues'] });

  const createMutation = useMutation({
    mutationFn: callQueuesService.create,
    onSuccess: () => {
      toast.success('Call queue created');
      invalidate();
      setDialogOpen(false);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: CallQueuePayload }) =>
      callQueuesService.update(id, payload),
    onSuccess: () => {
      toast.success('Call queue updated');
      invalidate();
      setDialogOpen(false);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const deleteMutation = useMutation({
    mutationFn: callQueuesService.delete,
    onSuccess: () => {
      toast.success('Call queue deleted');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const openCreate = () => {
    setEditing(null);
    setForm(emptyForm);
    setDialogOpen(true);
  };

  const openEdit = (queue: CallQueue) => {
    setEditing(queue);
    setForm({
      name: queue.name,
      description: queue.description ?? '',
      strategy: queue.strategy,
      agent_ring_timeout: queue.agent_ring_timeout,
      max_wait_seconds: queue.max_wait_seconds,
      wrap_up_seconds: queue.wrap_up_seconds,
      announce_position: queue.announce_position ?? false,
      announce_position_timeout: queue.announce_position_timeout ?? 60,
      announce_position_language: queue.announce_position_language ?? '',
      moh_recording_id: queue.moh_recording_id ? String(queue.moh_recording_id) : '',
      fallback_action: queue.fallback_action,
      fallback_extension_id: queue.fallback_extension_id ? String(queue.fallback_extension_id) : '',
      fallback_ring_group_id: queue.fallback_ring_group_id ? String(queue.fallback_ring_group_id) : '',
      fallback_ivr_menu_id: queue.fallback_ivr_menu_id ? String(queue.fallback_ivr_menu_id) : '',
      fallback_ai_assistant_id: queue.fallback_ai_assistant_id ? String(queue.fallback_ai_assistant_id) : '',
      fallback_ai_load_balancer_id: queue.fallback_ai_load_balancer_id
        ? String(queue.fallback_ai_load_balancer_id)
        : '',
      // Status is not editable in the dialog; preserve the existing value.
      status: queue.status,
      agents: (queue.agents ?? []).map((a) => a.id),
    });
    setDialogOpen(true);
  };

  const buildPayload = (): CallQueuePayload => ({
    name: form.name,
    description: form.description || undefined,
    strategy: form.strategy,
    agent_ring_timeout: form.agent_ring_timeout,
    max_wait_seconds: form.max_wait_seconds,
    wrap_up_seconds: form.wrap_up_seconds,
    announce_position: form.announce_position,
    announce_position_timeout: form.announce_position ? form.announce_position_timeout : null,
    announce_position_language: form.announce_position ? form.announce_position_language || null : null,
    moh_recording_id: form.moh_recording_id ? Number(form.moh_recording_id) : null,
    fallback_action: form.fallback_action,
    fallback_extension_id: form.fallback_extension_id ? Number(form.fallback_extension_id) : null,
    fallback_ring_group_id: form.fallback_ring_group_id ? Number(form.fallback_ring_group_id) : null,
    fallback_ivr_menu_id: form.fallback_ivr_menu_id ? Number(form.fallback_ivr_menu_id) : null,
    fallback_ai_assistant_id: form.fallback_ai_assistant_id ? Number(form.fallback_ai_assistant_id) : null,
    fallback_ai_load_balancer_id: form.fallback_ai_load_balancer_id
      ? Number(form.fallback_ai_load_balancer_id)
      : null,
    // New queues are always created active.
    status: editing ? editing.status : 'active',
    agents: form.agents,
  });

  const handleSubmit = () => {
    const payload = buildPayload();
    if (editing) {
      updateMutation.mutate({ id: editing.id, payload });
    } else {
      createMutation.mutate(payload);
    }
  };

  const columns: Column<CallQueue>[] = [
    {
      header: 'Strategy',
      cell: (q) => <Badge variant="outline">{q.strategy.replace(/_/g, ' ')}</Badge>,
    },
    {
      header: 'Agents',
      cell: (q) => (
        <span className="inline-flex items-center gap-1 text-muted-foreground">
          <Users className="h-3.5 w-3.5" />
          {q.agents_count ?? q.agents?.length ?? 0}
        </span>
      ),
    },
    {
      header: 'Status',
      cell: (q) => (
        <Badge variant={q.status === 'active' ? 'default' : 'secondary'}>
          {q.status === 'active' ? 'Active' : 'Inactive'}
        </Badge>
      ),
    },
  ];

  const set = <K extends keyof QueueFormState>(key: K, value: QueueFormState[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Call Queues</h1>
          <p className="text-sm text-muted-foreground">
            Stateful call distribution with agent login, hold music, and statistics.
          </p>
        </div>
        {canManage && (
          <Button onClick={openCreate}>
            <Plus className="mr-2 h-4 w-4" />
            New Queue
          </Button>
        )}
      </div>

      <Card>
        <CardContent className="pt-6">
          <StandardDataTable
            data={data?.data ?? []}
            columns={columns}
            isLoading={isLoading}
            identityIcon={Users}
            identityIconColor="text-indigo-600"
            identityIconBg="bg-indigo-100"
            getIdentityPrimary={(q) => q.name}
            getIdentitySecondary={(q) =>
              `Max wait ${q.max_wait_seconds}s · Wrap-up ${q.wrap_up_seconds}s`
            }
            onRowClick={(q) => navigate(`/ui/call-queues/${q.id}`)}
            emptyState={
              <EmptyState
                icon={Users}
                title="No call queues"
                description="Create a call queue to distribute calls to logged-in agents."
              />
            }
          />
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editing ? 'Edit Call Queue' : 'New Call Queue'}</DialogTitle>
          </DialogHeader>

          <div className="space-y-6">
            <div className="space-y-2">
              <Label>Name *</Label>
              <Input value={form.name} onChange={(e) => set('name', e.target.value)} />
            </div>

            <div className="space-y-2">
              <Label>Description</Label>
              <Textarea value={form.description} onChange={(e) => set('description', e.target.value)} rows={2} />
            </div>

            <CallQueueStrategySelector value={form.strategy} onChange={(v) => set('strategy', v)} />

            <div className="grid grid-cols-3 gap-4">
              <div className="space-y-2">
                <Label>Agent Ring Timeout</Label>
                <NumberSelect
                  value={form.agent_ring_timeout}
                  options={AGENT_RING_TIMEOUTS}
                  onChange={(v) => set('agent_ring_timeout', v)}
                />
              </div>
              <div className="space-y-2">
                <Label>Max Wait</Label>
                <NumberSelect
                  value={form.max_wait_seconds}
                  options={MAX_WAIT_OPTIONS}
                  onChange={(v) => set('max_wait_seconds', v)}
                />
              </div>
              <div className="space-y-2">
                <Label>Wrap-up</Label>
                <NumberSelect
                  value={form.wrap_up_seconds}
                  options={WRAP_UP_OPTIONS}
                  onChange={(v) => set('wrap_up_seconds', v)}
                />
              </div>
            </div>

            <div className="space-y-2">
              <Label>Hold Music (MOH)</Label>
              <Select
                value={form.moh_recording_id || 'none'}
                onValueChange={(v) => set('moh_recording_id', v === 'none' ? '' : v)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="None (spoken hold)" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">None (spoken hold)</SelectItem>
                  {(recordingsData?.data ?? []).map((r: any) => (
                    <SelectItem key={r.id} value={String(r.id)}>
                      <span className="inline-flex items-center gap-1">
                        <ListMusic className="h-3.5 w-3.5" />
                        {r.name}
                      </span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                Only recordings tagged as hold music are listed. Tag recordings in Announcements.
              </p>
            </div>

            <div className="space-y-3 rounded-lg border p-4">
              <div className="flex items-center justify-between">
                <div>
                  <Label>Announce Position</Label>
                  <p className="text-xs text-muted-foreground">
                    Periodically tell the caller their position in the queue.
                  </p>
                </div>
                <Switch
                  checked={form.announce_position}
                  onCheckedChange={(v) => set('announce_position', v)}
                />
              </div>

              {form.announce_position && (
                <div className="grid grid-cols-2 gap-4 pt-1">
                  <div className="space-y-2">
                    <Label>Announce Position Timeout</Label>
                    <Select
                      value={String(form.announce_position_timeout)}
                      onValueChange={(v) => set('announce_position_timeout', Number(v))}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {ANNOUNCE_INTERVALS.map((interval) => (
                          <SelectItem key={interval} value={String(interval)}>
                            Every {interval} seconds
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-2">
                    <Label>Announce Position Language</Label>
                    <Select
                      value={form.announce_position_language || 'default'}
                      onValueChange={(v) =>
                        set('announce_position_language', v === 'default' ? '' : v)
                      }
                    >
                      <SelectTrigger>
                        <SelectValue placeholder="Select language" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="default">Default (English)</SelectItem>
                        {languages.map((lang: any) => (
                          <SelectItem key={lang.code} value={lang.code}>
                            {lang.name} ({lang.code})
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                </div>
              )}
            </div>

            <div className="space-y-2">
              <Label>Agents *</Label>
              <Select
                value=""
                onValueChange={(v) => {
                  const id = Number(v);
                  if (!form.agents.includes(id)) {
                    set('agents', [...form.agents, id]);
                  }
                }}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Add an agent (user with extension)…" />
                </SelectTrigger>
                <SelectContent>
                  {agentUsers
                    .filter((u: any) => !form.agents.includes(u.id))
                    .map((u: any) => (
                      <SelectItem key={u.id} value={String(u.id)}>
                        {u.name} (ext {u.extension.extension_number})
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
              <div className="flex flex-wrap gap-2 pt-1">
                {form.agents.map((id) => {
                  const u = agentUsers.find((au: any) => au.id === id);
                  return (
                    <Badge key={id} variant="secondary" className="gap-1">
                      {u ? `${u.name} (ext ${u.extension.extension_number})` : id}
                      <button
                        type="button"
                        className="ml-1 text-muted-foreground hover:text-foreground"
                        onClick={() => set('agents', form.agents.filter((a) => a !== id))}
                      >
                        ×
                      </button>
                    </Badge>
                  );
                })}
              </div>
            </div>

            <div className="space-y-2">
              <Label>Overflow Fallback</Label>
              <DestinationTypeAndSelector
                typeValue={form.fallback_action as DestinationType}
                destinationValue={
                  form.fallback_action === 'extension'
                    ? form.fallback_extension_id
                    : form.fallback_action === 'ring_group'
                      ? form.fallback_ring_group_id
                      : form.fallback_action === 'ivr_menu'
                        ? form.fallback_ivr_menu_id
                        : form.fallback_action === 'ai_assistant'
                          ? form.fallback_ai_assistant_id
                          : form.fallback_action === 'ai_load_balancer'
                            ? form.fallback_ai_load_balancer_id
                            : ''
                }
                onChange={(type, destinationId) =>
                  setForm((prev) => ({
                    ...prev,
                    fallback_action: type,
                    fallback_extension_id: type === 'extension' ? destinationId : '',
                    fallback_ring_group_id: type === 'ring_group' ? destinationId : '',
                    fallback_ivr_menu_id: type === 'ivr_menu' ? destinationId : '',
                    fallback_ai_assistant_id: type === 'ai_assistant' ? destinationId : '',
                    fallback_ai_load_balancer_id: type === 'ai_load_balancer' ? destinationId : '',
                  }))
                }
                allowedTypes={['extension', 'ring_group', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'hangup']}
              />
              <p className="text-xs text-muted-foreground">
                Used when a caller exceeds max wait or no agents are available.
              </p>
            </div>
          </div>

          <DialogFooter className="gap-2 pt-6">
            {editing && (
              <Button
                variant="destructive"
                onClick={() => {
                  deleteMutation.mutate(editing.id);
                  setDialogOpen(false);
                }}
              >
                Delete
              </Button>
            )}
            <Button variant="outline" onClick={() => setDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={handleSubmit}
              disabled={
                !form.name ||
                form.agents.length === 0 ||
                (form.announce_position && !form.announce_position_language)
              }
            >
              {editing ? 'Save Changes' : 'Create Queue'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
