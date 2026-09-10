import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Network,
  RefreshCw,
  KeyRound,
  ChevronDown,
  Loader2,
  Search,
  BookOpen,
  PhoneIncoming,
  PhoneOutgoing,
  Copy,
  Check,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Card,
  CardContent,
} from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
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
import { toast } from 'sonner';
import { cn } from '@/lib/utils';
import { StandardDataTable } from '@/components/design-system';
import {
  trunksService,
  type Trunk,
  type TrunkTransport,
  type CreateTrunkRequest,
  type UpdateTrunkRequest,
} from '@/services/trunks.service';
import { useAuth } from '@/hooks/useAuth';

type DirectionFilter = 'all' | 'inbound' | 'outbound';

type TrunkFormData = {
  name: string;
  direction: 'inbound' | 'outbound';
  ip: string;
  port: string;
  transport: TrunkTransport;
  prefix: string;
  username: string;
  password: string;
  overwrite_from: boolean;
};

const emptyFormData: TrunkFormData = {
  name: '',
  direction: 'outbound',
  ip: '',
  port: '5060',
  transport: 'udp',
  prefix: '',
  username: '',
  password: '',
  overwrite_from: false,
};

// Cloudonix normalizes direction on create: inbound -> public-inbound,
// outbound -> public-outbound. Group them for display/filtering.
const directionGroup = (direction: Trunk['direction']): 'inbound' | 'outbound' =>
  direction === 'inbound' || direction === 'public-inbound' ? 'inbound' : 'outbound';

const isCloudonixUnavailable = (error: any): boolean =>
  error?.response?.status === 502 || error?.response?.data?.error === 'cloudonix_unavailable';

// Cloudonix platform ingress IP (fixed); change here if Cloudonix ever regionalizes ingress.
const CLOUDONIX_SIP_INGRESS_IP = '18.219.128.166';

const TrunksPage: React.FC = () => {
  const { user } = useAuth();
  const queryClient = useQueryClient();

  const [directionFilter, setDirectionFilter] = useState<DirectionFilter>('all');
  const [search, setSearch] = useState('');
  const [showDisabled, setShowDisabled] = useState(false);
  const [cheatsheetOpen, setCheatsheetOpen] = useState(false);
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [editingTrunk, setEditingTrunk] = useState<Trunk | null>(null);
  const [deleteTrunk, setDeleteTrunk] = useState<Trunk | null>(null);
  const [deleteAcknowledged, setDeleteAcknowledged] = useState(false);
  const [formData, setFormData] = useState<TrunkFormData>(emptyFormData);
  const [formErrors, setFormErrors] = useState<Partial<Record<keyof TrunkFormData, string>>>({});
  const [authOpen, setAuthOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const [ipCopied, setIpCopied] = useState(false);

  const canManageTrunks = user?.role === 'owner' || user?.role === 'pbx_admin';

  const {
    data: trunksResponse,
    isLoading,
    isRefetching,
    error: queryError,
    refetch,
  } = useQuery({
    queryKey: ['trunks'],
    queryFn: () => trunksService.listTrunks(),
  });

  const trunks = trunksResponse?.data ?? [];
  const sipHostname = trunksResponse?.meta?.sip_hostname;

  const inboundSipUris = useMemo(() => {
    if (!sipHostname) return [];
    return [
      `sip:${sipHostname}:5060;transport=udp;`,
      `sip:${sipHostname}:5060;transport=tcp;`,
      `sip:${sipHostname}:5061;transport=tls;`,
      `sip:${sipHostname}:443;transport=tls;`,
      `sip:${sipHostname}:8443;transport=tls;`,
    ];
  }, [sipHostname]);

  const inboundIpSipUris = useMemo(
    () => [
      `sip:${CLOUDONIX_SIP_INGRESS_IP}:5060;transport=udp;`,
      `sip:${CLOUDONIX_SIP_INGRESS_IP}:5060;transport=tcp;`,
      `sip:${CLOUDONIX_SIP_INGRESS_IP}:5061;transport=tls;`,
      `sip:${CLOUDONIX_SIP_INGRESS_IP}:443;transport=tls;`,
      `sip:${CLOUDONIX_SIP_INGRESS_IP}:8443;transport=tls;`,
    ],
    []
  );

  const copyHostname = async () => {
    if (!sipHostname) return;
    try {
      await navigator.clipboard.writeText(sipHostname);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      toast.error('Failed to copy hostname to clipboard');
    }
  };

  const copyIp = async () => {
    try {
      await navigator.clipboard.writeText(CLOUDONIX_SIP_INGRESS_IP);
      setIpCopied(true);
      setTimeout(() => setIpCopied(false), 2000);
    } catch {
      toast.error('Failed to copy IP address to clipboard');
    }
  };

  const filteredTrunks = useMemo(() => {
    const term = search.trim().toLowerCase();
    return trunks.filter((t) => {
      if (!showDisabled && !t.active) return false;
      if (directionFilter !== 'all' && directionGroup(t.direction) !== directionFilter) return false;
      if (term && !t.name.toLowerCase().includes(term)) return false;
      return true;
    });
  }, [trunks, directionFilter, search, showDisabled]);

  const hasActiveFilters = search.trim() !== '' || directionFilter !== 'all' || showDisabled;

  const extractValidationErrors = (error: any) => {
    const errors = error?.response?.data?.errors;
    if (errors && typeof errors === 'object') {
      const mapped: Partial<Record<keyof TrunkFormData, string>> = {};
      for (const [field, messages] of Object.entries(errors)) {
        mapped[field as keyof TrunkFormData] = Array.isArray(messages) ? String(messages[0]) : String(messages);
      }
      setFormErrors(mapped);
      return true;
    }
    return false;
  };

  const handleMutationError = (error: any, fallback: string) => {
    if (isCloudonixUnavailable(error)) {
      toast.error('Cloudonix is unreachable, try again');
    } else if (!extractValidationErrors(error)) {
      toast.error(error?.response?.data?.message || fallback);
    }
  };

  const createMutation = useMutation({
    mutationFn: (data: CreateTrunkRequest) => trunksService.createTrunk(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trunks'] });
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Trunk created successfully');
    },
    onError: (error: any) => handleMutationError(error, 'Failed to create trunk'),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateTrunkRequest }) =>
      trunksService.updateTrunk(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trunks'] });
      setIsEditDialogOpen(false);
      setEditingTrunk(null);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Trunk updated successfully');
    },
    onError: (error: any) => handleMutationError(error, 'Failed to update trunk'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => trunksService.deleteTrunk(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trunks'] });
      setDeleteTrunk(null);
      setDeleteAcknowledged(false);
      toast.success('Trunk deleted successfully');
    },
    onError: (error: any) => handleMutationError(error, 'Failed to delete trunk'),
  });

  const validateForm = (isCreate: boolean): boolean => {
    const errors: Partial<Record<keyof TrunkFormData, string>> = {};
    if (isCreate && !formData.name.trim()) errors.name = 'Name is required';
    if (!formData.ip.trim()) errors.ip = 'Address is required';
    const port = parseInt(formData.port, 10);
    if (!formData.port || isNaN(port) || port < 1 || port > 65535) {
      errors.port = 'Port must be between 1 and 65535';
    }
    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const isCreate = !editingTrunk;
    if (!validateForm(isCreate)) return;

    const port = parseInt(formData.port, 10);

    if (editingTrunk) {
      const data: UpdateTrunkRequest = {
        ip: formData.ip.trim(),
        port,
        transport: formData.transport,
        prefix: formData.prefix.trim() || undefined,
        overwrite_from: formData.overwrite_from,
      };
      if (formData.username.trim()) data.username = formData.username.trim();
      if (formData.password) data.password = formData.password;
      updateMutation.mutate({ id: editingTrunk.id, data });
    } else {
      const data: CreateTrunkRequest = {
        name: formData.name.trim(),
        ip: formData.ip.trim(),
        port,
        transport: formData.transport,
        direction: formData.direction,
      };
      if (formData.prefix.trim()) data.prefix = formData.prefix.trim();
      if (formData.username.trim()) data.username = formData.username.trim();
      if (formData.password) data.password = formData.password;
      if (formData.overwrite_from) data.overwrite_from = true;
      createMutation.mutate(data);
    }
  };

  const openCreateDialog = () => {
    setFormData(emptyFormData);
    setFormErrors({});
    setAuthOpen(false);
    setIsCreateDialogOpen(true);
  };

  const openEditDialog = (trunk: Trunk) => {
    setEditingTrunk(trunk);
    setFormData({
      name: trunk.name,
      direction: directionGroup(trunk.direction),
      ip: trunk.ip,
      port: String(trunk.port),
      transport: trunk.transport,
      prefix: trunk.prefix || '',
      username: trunk.username || '',
      password: '',
      overwrite_from: trunk.overwrite_from,
    });
    setFormErrors({});
    setAuthOpen(trunk.has_credentials);
    setIsEditDialogOpen(true);
  };

  const renderFormFields = (isCreate: boolean) => (
    <div className="grid gap-4 py-4">
      {isCreate && (
        <>
          <div className="grid gap-2">
            <Label htmlFor="trunk-name">Name</Label>
            <Input
              id="trunk-name"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              placeholder="outbound-telnyx"
            />
            {formErrors.name && <p className="text-sm text-destructive">{formErrors.name}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="trunk-direction">Direction</Label>
            <Select
              value={formData.direction}
              onValueChange={(value) => setFormData({ ...formData, direction: value as 'inbound' | 'outbound' })}
            >
              <SelectTrigger id="trunk-direction">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="inbound">Inbound</SelectItem>
                <SelectItem value="outbound">Outbound</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              Cloudonix normalizes direction on creation — inbound trunks appear as "public-inbound", outbound as "public-outbound".
            </p>
          </div>
        </>
      )}

      <div className="grid gap-2">
        <Label htmlFor="trunk-ip">Address</Label>
        <Input
          id="trunk-ip"
          value={formData.ip}
          onChange={(e) => setFormData({ ...formData, ip: e.target.value })}
          placeholder="192.0.2.10 or sip.carrier.com"
        />
        {formErrors.ip && <p className="text-sm text-destructive">{formErrors.ip}</p>}
      </div>

      <div className="grid grid-cols-2 gap-4">
        <div className="grid gap-2">
          <Label htmlFor="trunk-port">Port</Label>
          <Input
            id="trunk-port"
            type="number"
            min={1}
            max={65535}
            value={formData.port}
            onChange={(e) => setFormData({ ...formData, port: e.target.value })}
          />
          {formErrors.port && <p className="text-sm text-destructive">{formErrors.port}</p>}
        </div>
        <div className="grid gap-2">
          <Label htmlFor="trunk-transport">Transport</Label>
          <Select
            value={formData.transport}
            onValueChange={(value) => setFormData({ ...formData, transport: value as TrunkTransport })}
          >
            <SelectTrigger id="trunk-transport">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="udp">UDP</SelectItem>
              <SelectItem value="tcp">TCP</SelectItem>
              <SelectItem value="tls">TLS</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="grid gap-2">
        <Label htmlFor="trunk-prefix">Prefix (optional)</Label>
        <Input
          id="trunk-prefix"
          value={formData.prefix}
          onChange={(e) => setFormData({ ...formData, prefix: e.target.value })}
          placeholder="e.g. 972"
        />
        <p className="text-xs text-muted-foreground">Prepended to incoming calls.</p>
        {formErrors.prefix && <p className="text-sm text-destructive">{formErrors.prefix}</p>}
      </div>

      <Collapsible open={authOpen} onOpenChange={setAuthOpen}>
        <CollapsibleTrigger asChild>
          <Button type="button" variant="outline" className="w-full justify-between">
            <span className="flex items-center gap-2">
              <KeyRound className="h-4 w-4" />
              Authentication
            </span>
            <ChevronDown className={cn('h-4 w-4 transition-transform', authOpen && 'rotate-180')} />
          </Button>
        </CollapsibleTrigger>
        <CollapsibleContent className="pt-4 space-y-4">
          <div className="grid gap-2">
            <Label htmlFor="trunk-username">Username</Label>
            <Input
              id="trunk-username"
              value={formData.username}
              onChange={(e) => setFormData({ ...formData, username: e.target.value })}
              autoComplete="off"
            />
            {formErrors.username && <p className="text-sm text-destructive">{formErrors.username}</p>}
          </div>
          <div className="grid gap-2">
            <Label htmlFor="trunk-password">Password</Label>
            <Input
              id="trunk-password"
              type="password"
              value={formData.password}
              onChange={(e) => setFormData({ ...formData, password: e.target.value })}
              placeholder={isCreate ? '' : 'Leave blank to keep unchanged'}
              autoComplete="new-password"
            />
            {formErrors.password && <p className="text-sm text-destructive">{formErrors.password}</p>}
          </div>
          <div className="flex items-start gap-3">
            <Switch
              id="trunk-overwrite-from"
              checked={formData.overwrite_from}
              onCheckedChange={(checked) => setFormData({ ...formData, overwrite_from: checked })}
            />
            <div className="grid gap-1">
              <Label htmlFor="trunk-overwrite-from" className="cursor-pointer">
                Overwrite From header
              </Label>
              <p className="text-xs text-muted-foreground">
                Set outbound From header to the auth username.
              </p>
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>
  );

  const isSubmitting = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Network className="h-8 w-8" />
            Trunks
          </h1>
          <p className="text-muted-foreground mt-1">
            Inbound trunks bring calls from your carrier into the PBX; outbound trunks send calls from the PBX to the world.
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Trunks</span>
          </div>
        </div>
        {canManageTrunks && (
          <Button onClick={openCreateDialog}>
            <Plus className="mr-2 h-4 w-4" />
            Add Trunk
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap items-center gap-3">
            <Tabs value={directionFilter} onValueChange={(v) => setDirectionFilter(v as DirectionFilter)}>
              <TabsList>
                <TabsTrigger value="all">All</TabsTrigger>
                <TabsTrigger value="inbound">Inbound</TabsTrigger>
                <TabsTrigger value="outbound">Outbound</TabsTrigger>
              </TabsList>
            </Tabs>

            <div className="relative">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search by name..."
                className="pl-8 w-56"
                aria-label="Search trunks by name"
              />
            </div>

            <Button
              variant={showDisabled ? 'secondary' : 'outline'}
              onClick={() => setShowDisabled((v) => !v)}
              aria-pressed={showDisabled}
            >
              {showDisabled ? 'Hide disabled' : 'Show disabled'}
            </Button>

            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
              className="ml-auto"
            >
              <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Cheatsheet */}
      <Collapsible open={cheatsheetOpen} onOpenChange={setCheatsheetOpen}>
        <Card>
          <CollapsibleTrigger asChild>
            <button
              type="button"
              className="flex w-full items-center justify-between p-4 text-left"
              aria-expanded={cheatsheetOpen}
            >
              <span className="flex items-center gap-2 font-semibold">
                <BookOpen className="h-4 w-4 text-muted-foreground" />
                Your SIP Trunk Configuration Cheatsheet
              </span>
              <ChevronDown className={cn('h-4 w-4 transition-transform text-muted-foreground', cheatsheetOpen && 'rotate-180')} />
            </button>
          </CollapsibleTrigger>
          <CollapsibleContent>
            <CardContent className="pt-0 pb-4 px-4">
              <div className="grid gap-4 md:grid-cols-2">
                <Card>
                  <CardContent className="p-4">
                    <p className="flex items-center gap-2 font-medium text-foreground">
                      <PhoneIncoming className="h-4 w-4 text-muted-foreground" />
                      Inbound SIP Trunk Information
                    </p>
                    <Tabs defaultValue="dns" className="mt-3">
                      <TabsList>
                        <TabsTrigger value="dns">DNS Based Routing</TabsTrigger>
                        <TabsTrigger value="ip">IP Based Routing</TabsTrigger>
                      </TabsList>
                      <TabsContent value="dns" className="mt-3">
                        <p className="text-sm text-muted-foreground">
                          The recommended option. Your provider sends calls to your domain's SIP hostname, and DNS resolves it to Cloudonix's ingress automatically — no fixed IP to manage, and failover is handled for you.
                        </p>
                        {sipHostname ? (
                          <>
                            <p className="mt-3 text-sm text-muted-foreground">
                              Your SIP trunk hostname for inbound calls:
                            </p>
                            <div className="mt-1 flex items-center gap-2">
                              <code className="flex-1 rounded-md bg-muted px-2 py-1.5 font-mono text-sm break-all">
                                {sipHostname}
                              </code>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={copyHostname}
                                aria-label="Copy hostname to clipboard"
                              >
                                {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                                {copied ? 'Copied' : 'Copy'}
                              </Button>
                            </div>
                            <p className="mt-3 text-sm text-muted-foreground">
                              Use any of the following SIP URIs to send calls to your Cloudonix domain:
                            </p>
                            <ul className="mt-1 space-y-1">
                              {inboundSipUris.map((uri) => (
                                <li key={uri}>
                                  <code className="block rounded-md bg-muted px-2 py-1.5 font-mono text-xs break-all">
                                    {uri}
                                  </code>
                                </li>
                              ))}
                            </ul>
                          </>
                        ) : (
                          <p className="mt-2 text-sm text-muted-foreground">
                            Cloudonix settings not configured.
                          </p>
                        )}
                      </TabsContent>
                      <TabsContent value="ip" className="mt-3">
                        <p className="text-sm text-muted-foreground">
                          Use this when your provider or equipment cannot route to a hostname and needs a fixed IP address. Calls are sent directly to Cloudonix's ingress IP — you must configure your call origin (your source IP) for these calls to be accepted.
                        </p>
                        <p className="mt-3 text-sm text-muted-foreground">
                          Your SIP trunk IP address for inbound calls:
                        </p>
                        <div className="mt-1 flex items-center gap-2">
                          <code className="flex-1 rounded-md bg-muted px-2 py-1.5 font-mono text-sm break-all">
                            {CLOUDONIX_SIP_INGRESS_IP}
                          </code>
                          <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={copyIp}
                            aria-label="Copy IP address to clipboard"
                          >
                            {ipCopied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                            {ipCopied ? 'Copied' : 'Copy'}
                          </Button>
                        </div>
                        <p className="mt-3 text-sm text-muted-foreground">
                          Use any of the below SIP URIs to send calls to Cloudonix. You must configure your call origin for these to work
                        </p>
                        <ul className="mt-1 space-y-1">
                          {inboundIpSipUris.map((uri) => (
                            <li key={uri}>
                              <code className="block rounded-md bg-muted px-2 py-1.5 font-mono text-xs break-all">
                                {uri}
                              </code>
                            </li>
                          ))}
                        </ul>
                      </TabsContent>
                    </Tabs>
                  </CardContent>
                </Card>
                <Card>
                  <CardContent className="p-4">
                    <p className="flex items-center gap-2 font-medium text-foreground">
                      <PhoneOutgoing className="h-4 w-4 text-muted-foreground" />
                      Outbound SIP Trunk Information
                    </p>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Configuration details coming soon.
                    </p>
                  </CardContent>
                </Card>
              </div>
            </CardContent>
          </CollapsibleContent>
        </Card>
      </Collapsible>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          {queryError ? (
            <div className="text-center py-12">
              <Network className="h-12 w-12 mx-auto text-destructive mb-4" />
              <h3 className="text-lg font-semibold mb-2">Failed to load trunks</h3>
              <p className="text-sm text-muted-foreground mb-4">
                {isCloudonixUnavailable(queryError)
                  ? 'Cloudonix is unreachable, try again'
                  : 'An error occurred while loading trunks'}
              </p>
              <Button variant="outline" onClick={() => refetch()}>
                <RefreshCw className="h-4 w-4 mr-2" />
                Retry
              </Button>
            </div>
          ) : (
            <StandardDataTable<Trunk>
              data={filteredTrunks}
              isLoading={isLoading}
              onRowClick={(trunk) => canManageTrunks && openEditDialog(trunk)}
              identityIcon={Network}
              identityIconBg="bg-blue-100"
              identityIconColor="text-blue-600"
              getIdentityPrimary={(trunk) => trunk.name}
              getIdentitySecondary={(trunk) => trunk.uuid}
              onIdentityClick={(trunk) => canManageTrunks && openEditDialog(trunk)}
              canView={false}
              canEdit={false}
              onDelete={canManageTrunks ? (trunk) => { setDeleteAcknowledged(false); setDeleteTrunk(trunk); } : undefined}
              columns={[
                {
                  header: 'Direction',
                  cell: (trunk) => (
                    <Badge
                      variant="outline"
                      className={cn(
                        directionGroup(trunk.direction) === 'inbound'
                          ? 'bg-blue-50 text-blue-700 border-blue-200'
                          : 'bg-purple-50 text-purple-700 border-purple-200'
                      )}
                    >
                      {directionGroup(trunk.direction) === 'inbound' ? 'Inbound' : 'Outbound'}
                    </Badge>
                  ),
                },
                {
                  header: 'Address',
                  cell: (trunk) => (
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-sm">{trunk.ip}:{trunk.port}</span>
                      <Badge variant="secondary" className="uppercase text-[10px] px-1.5 py-0">
                        {trunk.transport}
                      </Badge>
                    </div>
                  ),
                },
                {
                  header: 'Prefix',
                  cell: (trunk) => trunk.prefix || '-',
                },
                {
                  header: 'Auth',
                  cell: (trunk) =>
                    trunk.has_credentials ? (
                      <TooltipProvider>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <KeyRound className="h-4 w-4 text-muted-foreground" />
                          </TooltipTrigger>
                          <TooltipContent>
                            Username: {trunk.username || '—'}
                          </TooltipContent>
                        </Tooltip>
                      </TooltipProvider>
                    ) : (
                      '-'
                    ),
                },
                {
                  header: 'Status',
                  cell: (trunk) => (
                    <Badge
                      variant="outline"
                      className={cn(
                        trunk.active
                          ? 'bg-green-50 text-green-700 border-green-200'
                          : 'bg-gray-100 text-gray-700 border-gray-200'
                      )}
                    >
                      {trunk.active ? 'Active' : 'Inactive'}
                    </Badge>
                  ),
                },
                {
                  header: 'Created',
                  cell: (trunk) => (trunk.created_at ? new Date(trunk.created_at).toLocaleDateString() : '-'),
                },
              ]}
              emptyState={
                <div className="flex flex-col items-center justify-center p-12 text-center">
                  <Network className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <h3 className="text-lg font-semibold mb-2">No trunks found</h3>
                  <p className="text-sm text-muted-foreground max-w-sm mb-6">
                    {hasActiveFilters
                      ? 'Try adjusting your filters'
                      : 'Get started by creating your first trunk'}
                  </p>
                  {!hasActiveFilters && canManageTrunks && (
                    <Button onClick={openCreateDialog} size="lg">
                      Create Trunk
                    </Button>
                  )}
                </div>
              }
            />
          )}
        </CardContent>
      </Card>

      {/* Create Dialog */}
      <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
        <DialogContent className="sm:max-w-[500px]">
          <form onSubmit={handleSubmit}>
            <DialogHeader>
              <DialogTitle>Add Trunk</DialogTitle>
              <DialogDescription>
                Create a new SIP trunk to connect your PBX with a carrier.
              </DialogDescription>
            </DialogHeader>
            {renderFormFields(true)}
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsCreateDialogOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {createMutation.isPending ? 'Creating...' : 'Create Trunk'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent className="sm:max-w-[500px]">
          <form onSubmit={handleSubmit}>
            <DialogHeader>
              <DialogTitle>Edit Trunk</DialogTitle>
              <DialogDescription>
                Update settings for "{editingTrunk?.name}". Name and direction cannot be changed.
              </DialogDescription>
            </DialogHeader>
            {renderFormFields(false)}
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => setIsEditDialogOpen(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {updateMutation.isPending ? 'Updating...' : 'Update Trunk'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!deleteTrunk} onOpenChange={() => setDeleteTrunk(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Trunk</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{deleteTrunk?.name}"? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>

          {deleteTrunk && deleteTrunk.in_use_by.length > 0 && (
            <div className="rounded-md border border-amber-200 bg-amber-50 p-3 space-y-2">
              <p className="text-sm font-medium text-amber-800">
                This trunk is referenced by {deleteTrunk.in_use_by.length} outbound whitelist {deleteTrunk.in_use_by.length === 1 ? 'entry' : 'entries'}:
              </p>
              <ul className="list-disc list-inside text-sm text-amber-700">
                {deleteTrunk.in_use_by.map((name) => (
                  <li key={name}>{name}</li>
                ))}
              </ul>
              <div className="flex items-center gap-2 pt-1">
                <Checkbox
                  id="acknowledge-delete"
                  checked={deleteAcknowledged}
                  onCheckedChange={(checked) => setDeleteAcknowledged(checked === true)}
                />
                <Label htmlFor="acknowledge-delete" className="text-sm text-amber-800 cursor-pointer">
                  I understand these entries may stop working
                </Label>
              </div>
            </div>
          )}

          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteTrunk && deleteMutation.mutate(deleteTrunk.id)}
              disabled={!!deleteTrunk && deleteTrunk.in_use_by.length > 0 && !deleteAcknowledged}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default TrunksPage;
