import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Search,
  Filter,
  ShieldBan,
  RefreshCw,
  MoreHorizontal,
  Edit,
  Trash2,
  BarChart3,
  PhoneOff,
  Phone,
  Music,
  Globe,
  Target,
  Clock,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
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
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { EmptyState, StatCard } from '@/components/design-system';
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
import { inboundBlacklistService } from '@/services/inboundBlacklist.service';
import { phoneNumbersService } from '@/services/createResourceService';
import { useAuth } from '@/hooks/useAuth';
import type {
  InboundBlacklist,
  BlockedCallLog,
  InboundBlacklistStats,
  CreateInboundBlacklistRequest,
  UpdateInboundBlacklistRequest,
  InboundBlacklistMatchType,
  InboundBlacklistRejectionStrategy,
  DIDNumber,
} from '@/types';

type BlacklistFormData = {
  caller_id_pattern: string;
  match_type: InboundBlacklistMatchType;
  description: string;
  rejection_strategy: InboundBlacklistRejectionStrategy;
  did_number_id: number | null;
  is_global: boolean;
  torment_room_prefix: string;
  torment_music_timeout: number;
  status: 'active' | 'inactive';
  expires_at: string;
};

const emptyFormData: BlacklistFormData = {
  caller_id_pattern: '',
  match_type: 'exact',
  description: '',
  rejection_strategy: 'drop',
  did_number_id: null,
  is_global: true,
  torment_room_prefix: '',
  torment_music_timeout: 300,
  status: 'active',
  expires_at: '',
};

const matchTypeLabels: Record<InboundBlacklistMatchType, string> = {
  exact: 'Exact Match',
  prefix: 'Prefix Match',
  wildcard: 'Wildcard Pattern',
};

const strategyLabels: Record<InboundBlacklistRejectionStrategy, string> = {
  drop: 'Drop (Silent)',
  reject: 'Reject (Message)',
  torment: 'Torment (Music)',
};

const strategyIcons: Record<InboundBlacklistRejectionStrategy, typeof PhoneOff> = {
  drop: PhoneOff,
  reject: Phone,
  torment: Music,
};

const InboundBlacklistPage: React.FC = () => {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('entries');
  const [searchQuery, setSearchQuery] = useState('');
  const [strategyFilter, setStrategyFilter] = useState<InboundBlacklistRejectionStrategy | 'all'>('all');
  const [matchTypeFilter, setMatchTypeFilter] = useState<InboundBlacklistMatchType | 'all'>('all');
  const [scopeFilter, setScopeFilter] = useState<'global' | 'did_specific' | 'all'>('all');

  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [editingItem, setEditingItem] = useState<InboundBlacklist | null>(null);
  const [deleteItem, setDeleteItem] = useState<InboundBlacklist | null>(null);
  const [formData, setFormData] = useState<BlacklistFormData>(emptyFormData);
  const [formErrors, setFormErrors] = useState<Partial<Record<keyof BlacklistFormData, string>>>({});

  // Pagination
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [logsPage, setLogsPage] = useState(1);

  // Check permissions
  const canManageBlacklist = user?.role === 'owner' || user?.role === 'pbx_admin';

  // Fetch blacklist entries
  const {
    data: blacklistData,
    isLoading: isLoadingBlacklist,
    refetch: refetchBlacklist,
  } = useQuery({
    queryKey: ['inbound-blacklist', { search: searchQuery, strategy: strategyFilter, match_type: matchTypeFilter, scope: scopeFilter, page: currentPage }],
    queryFn: () => inboundBlacklistService.getAll({
      search: searchQuery || undefined,
      rejection_strategy: strategyFilter !== 'all' ? strategyFilter : undefined,
      match_type: matchTypeFilter !== 'all' ? matchTypeFilter : undefined,
      scope: scopeFilter !== 'all' ? scopeFilter : undefined,
      per_page: perPage,
      page: currentPage,
    }),
  });

  // Fetch statistics
  const {
    data: statsData,
    isLoading: isLoadingStats,
  } = useQuery({
    queryKey: ['inbound-blacklist-statistics'],
    queryFn: () => inboundBlacklistService.getStatistics(),
  });

  // Fetch blocked call logs
  const {
    data: logsData,
    isLoading: isLoadingLogs,
  } = useQuery({
    queryKey: ['blocked-call-logs', { page: logsPage }],
    queryFn: () => inboundBlacklistService.getBlockedLogs({
      per_page: 20,
      page: logsPage,
    }),
    enabled: activeTab === 'logs',
  });

  // Fetch phone numbers for DID selection
  const {
    data: phoneNumbersData,
  } = useQuery({
    queryKey: ['phone-numbers'],
    queryFn: () => phoneNumbersService.getAll({ per_page: 100 }),
  });

  const phoneNumbers = phoneNumbersData?.data || [];

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateInboundBlacklistRequest) => inboundBlacklistService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist'] });
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist-statistics'] });
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Blacklist entry created successfully');
    },
    onError: (error: any) => {
      if (error.response?.data?.error?.details) {
        const errors: Record<string, string> = {};
        error.response.data.error.details.forEach((detail: any) => {
          errors[detail.field] = detail.message;
        });
        setFormErrors(errors);
      } else {
        toast.error(error.response?.data?.message || 'Failed to create blacklist entry');
      }
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: number; data: UpdateInboundBlacklistRequest }) =>
      inboundBlacklistService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist'] });
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist-statistics'] });
      setIsEditDialogOpen(false);
      setEditingItem(null);
      setFormData(emptyFormData);
      setFormErrors({});
      toast.success('Blacklist entry updated successfully');
    },
    onError: (error: any) => {
      if (error.response?.data?.error?.details) {
        const errors: Record<string, string> = {};
        error.response.data.error.details.forEach((detail: any) => {
          errors[detail.field] = detail.message;
        });
        setFormErrors(errors);
      } else {
        toast.error(error.response?.data?.message || 'Failed to update blacklist entry');
      }
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: number) => inboundBlacklistService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist'] });
      queryClient.invalidateQueries({ queryKey: ['inbound-blacklist-statistics'] });
      setDeleteItem(null);
      toast.success('Blacklist entry deleted successfully');
    },
    onError: () => {
      toast.error('Failed to delete blacklist entry');
    },
  });

  // Handle form submission
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setFormErrors({});

    const data: CreateInboundBlacklistRequest = {
      caller_id_pattern: formData.caller_id_pattern,
      match_type: formData.match_type,
      description: formData.description || undefined,
      rejection_strategy: formData.rejection_strategy,
      did_number_id: formData.did_number_id || undefined,
      is_global: formData.is_global,
      status: formData.status,
      torment_room_prefix: formData.rejection_strategy === 'torment' ? formData.torment_room_prefix : undefined,
      torment_music_timeout: formData.rejection_strategy === 'torment' ? formData.torment_music_timeout : undefined,
      expires_at: formData.expires_at || undefined,
    };

    if (editingItem) {
      updateMutation.mutate({ id: editingItem.id, data });
    } else {
      createMutation.mutate(data);
    }
  };

  // Open edit dialog
  const openEditDialog = (item: InboundBlacklist) => {
    setEditingItem(item);
    setFormData({
      caller_id_pattern: item.caller_id_pattern,
      match_type: item.match_type,
      description: item.description || '',
      rejection_strategy: item.rejection_strategy,
      did_number_id: item.did_number_id || null,
      is_global: item.is_global,
      torment_room_prefix: item.torment_room_prefix || '',
      torment_music_timeout: item.torment_music_timeout || 300,
      status: item.status,
      expires_at: item.expires_at || '',
    });
    setIsEditDialogOpen(true);
  };

  // Open create dialog
  const openCreateDialog = () => {
    setEditingItem(null);
    setFormData(emptyFormData);
    setFormErrors({});
    setIsCreateDialogOpen(true);
  };

  // Reset filters
  const resetFilters = () => {
    setSearchQuery('');
    setStrategyFilter('all');
    setMatchTypeFilter('all');
    setScopeFilter('all');
    setCurrentPage(1);
  };

  const blacklist = blacklistData?.data || [];
  const stats = statsData?.data;
  const logs = logsData?.data || [];
  const totalLogPages = logsData?.meta?.last_page || 1;
  const totalBlacklistPages = blacklistData?.meta?.last_page || 1;

  // Strategy badge colors
  const getStrategyBadgeColor = (strategy: InboundBlacklistRejectionStrategy) => {
    switch (strategy) {
      case 'drop':
        return 'bg-red-100 text-red-800 border-red-200';
      case 'reject':
        return 'bg-orange-100 text-orange-800 border-orange-200';
      case 'torment':
        return 'bg-purple-100 text-purple-800 border-purple-200';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <ShieldBan className="h-8 w-8" />
            Inbound Blacklist
          </h1>
          <p className="text-muted-foreground mt-1">
            Block unwanted callers with customizable rejection strategies
          </p>
        </div>
        {canManageBlacklist && (
          <Button onClick={openCreateDialog}>
            <Plus className="h-4 w-4 mr-2" />
            Add Blacklist Entry
          </Button>
        )}
      </div>

      {/* Statistics Cards */}
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
          <StatCard
            title="Total Entries"
            value={stats.total_entries}
            icon={ShieldBan}
            color="primary"
          />
          <StatCard
            title="Active Entries"
            value={stats.active_entries}
            icon={Target}
            color="success"
            description={`${stats.global_entries} global`}
          />
          <StatCard
            title="Blocked Today"
            value={stats.blocked_calls_today}
            icon={PhoneOff}
            color="danger"
            description={`${stats.total_blocked_calls} total`}
          />
          <StatCard
            title="This Week"
            value={stats.blocked_calls_this_week}
            icon={Clock}
            color="warning"
          />
        </div>
      )}

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="entries">Blacklist Entries</TabsTrigger>
          <TabsTrigger value="logs">Blocked Call Logs</TabsTrigger>
          <TabsTrigger value="stats">Statistics</TabsTrigger>
        </TabsList>

        {/* Entries Tab */}
        <TabsContent value="entries" className="space-y-4">
          {/* Filters */}
          <Card>
            <CardContent className="pt-6">
              <div className="flex flex-wrap gap-4">
                <div className="flex-1 min-w-[300px]">
                  <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                      placeholder="Search by caller ID or description..."
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      className="pl-10"
                    />
                  </div>
                </div>
                <Select value={strategyFilter} onValueChange={(v) => setStrategyFilter(v as InboundBlacklistRejectionStrategy | 'all')}>
                  <SelectTrigger className="w-[180px]">
                    <Filter className="h-4 w-4 mr-2" />
                    <SelectValue placeholder="Strategy" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Strategies</SelectItem>
                    <SelectItem value="drop">Drop</SelectItem>
                    <SelectItem value="reject">Reject</SelectItem>
                    <SelectItem value="torment">Torment</SelectItem>
                  </SelectContent>
                </Select>
                <Select value={matchTypeFilter} onValueChange={(v) => setMatchTypeFilter(v as InboundBlacklistMatchType | 'all')}>
                  <SelectTrigger className="w-[180px]">
                    <Filter className="h-4 w-4 mr-2" />
                    <SelectValue placeholder="Match Type" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Match Types</SelectItem>
                    <SelectItem value="exact">Exact</SelectItem>
                    <SelectItem value="prefix">Prefix</SelectItem>
                    <SelectItem value="wildcard">Wildcard</SelectItem>
                  </SelectContent>
                </Select>
                <Select value={scopeFilter} onValueChange={(v) => setScopeFilter(v as 'global' | 'did_specific' | 'all')}>
                  <SelectTrigger className="w-[180px]">
                    <Globe className="h-4 w-4 mr-2" />
                    <SelectValue placeholder="Scope" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Scopes</SelectItem>
                    <SelectItem value="global">Global</SelectItem>
                    <SelectItem value="did_specific">DID-Specific</SelectItem>
                  </SelectContent>
                </Select>
                <Button variant="outline" onClick={resetFilters}>
                  Reset
                </Button>
                <Button variant="ghost" size="icon" onClick={() => refetchBlacklist()}>
                  <RefreshCw className="h-4 w-4" />
                </Button>
              </div>
            </CardContent>
          </Card>

          {/* Blacklist Table */}
          <Card>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Caller ID Pattern</TableHead>
                    <TableHead>Match Type</TableHead>
                    <TableHead>Strategy</TableHead>
                    <TableHead>Scope</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Blocked</TableHead>
                    <TableHead>Description</TableHead>
                    {canManageBlacklist && <TableHead className="text-right">Actions</TableHead>}
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoadingBlacklist ? (
                    <TableRow>
                      <TableCell colSpan={canManageBlacklist ? 8 : 7} className="text-center py-8">
                        Loading...
                      </TableCell>
                    </TableRow>
                  ) : blacklist.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={canManageBlacklist ? 8 : 7} className="text-center py-12">
                        <EmptyState
                          icon={ShieldBan}
                          title="No blacklist entries found"
                          description={searchQuery || strategyFilter !== 'all' || matchTypeFilter !== 'all' || scopeFilter !== 'all'
                            ? 'Try adjusting your filters'
                            : 'Get started by creating your first blacklist entry'}
                          action={canManageBlacklist && !searchQuery && strategyFilter === 'all' && matchTypeFilter === 'all' && scopeFilter === 'all' ? {
                            label: "Add Blacklist Entry",
                            onClick: openCreateDialog
                          } : undefined}
                        />
                      </TableCell>
                    </TableRow>
                  ) : (
                    blacklist.map((item: InboundBlacklist) => {
                      const StrategyIcon = strategyIcons[item.rejection_strategy];
                      return (
                        <TableRow key={item.id}>
                          <TableCell className="font-mono text-sm">{item.caller_id_pattern}</TableCell>
                          <TableCell>
                            <Badge variant="outline">{matchTypeLabels[item.match_type]}</Badge>
                          </TableCell>
                          <TableCell>
                            <Badge className={cn(getStrategyBadgeColor(item.rejection_strategy))}>
                              <StrategyIcon className="h-3 w-3 mr-1 inline" />
                              {strategyLabels[item.rejection_strategy]}
                            </Badge>
                          </TableCell>
                          <TableCell>
                            {item.is_global ? (
                              <Badge variant="outline" className="bg-blue-50">
                                <Globe className="h-3 w-3 mr-1 inline" />
                                Global
                              </Badge>
                            ) : (
                              <Badge variant="outline" className="bg-purple-50">
                                <Target className="h-3 w-3 mr-1 inline" />
                                {item.did_number?.friendly_name || item.did_number?.phone_number || 'DID'}
                              </Badge>
                            )}
                          </TableCell>
                          <TableCell>
                            <Badge variant={item.status === 'active' ? 'default' : 'secondary'}>
                              {item.status === 'active' ? 'Active' : 'Inactive'}
                            </Badge>
                          </TableCell>
                          <TableCell className="font-semibold">{item.blocked_count}</TableCell>
                          <TableCell>
                            <span className="text-muted-foreground text-sm truncate max-w-[200px] block">
                              {item.description || '-'}
                            </span>
                          </TableCell>
                          {canManageBlacklist && (
                            <TableCell className="text-right">
                              <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                  <Button variant="ghost" size="sm">
                                    <MoreHorizontal className="h-4 w-4" />
                                  </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                  <DropdownMenuItem onClick={() => openEditDialog(item)}>
                                    <Edit className="h-4 w-4 mr-2" />
                                    Edit
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    onClick={() => setDeleteItem(item)}
                                    className="text-red-600"
                                  >
                                    <Trash2 className="h-4 w-4 mr-2" />
                                    Delete
                                  </DropdownMenuItem>
                                </DropdownMenuContent>
                              </DropdownMenu>
                            </TableCell>
                          )}
                        </TableRow>
                      );
                    })
                  )}
                </TableBody>
              </Table>

              {/* Pagination */}
              {totalBlacklistPages > 1 && (
                <div className="flex items-center justify-between px-6 py-4 border-t">
                  <div className="text-sm text-muted-foreground">
                    Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, blacklistData?.meta?.total || 0)} of {blacklistData?.meta?.total || 0} entries
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                      disabled={currentPage === 1}
                    >
                      Previous
                    </Button>
                    <div className="text-sm">
                      Page {currentPage} of {totalBlacklistPages}
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(p => Math.min(totalBlacklistPages, p + 1))}
                      disabled={currentPage === totalBlacklistPages}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Logs Tab */}
        <TabsContent value="logs">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <BarChart3 className="h-5 w-5" />
                Blocked Call Logs
              </CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Caller ID</TableHead>
                    <TableHead>Called Number</TableHead>
                    <TableHead>Strategy</TableHead>
                    <TableHead>Matched Pattern</TableHead>
                    <TableHead>Blocked At</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isLoadingLogs ? (
                    <TableRow>
                      <TableCell colSpan={5} className="text-center py-8">
                        Loading...
                      </TableCell>
                    </TableRow>
                  ) : logs.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={5} className="text-center py-12">
                        <EmptyState
                          icon={PhoneOff}
                          title="No blocked calls yet"
                          description="Blocked calls will appear here when blacklisted callers attempt to reach your numbers"
                        />
                      </TableCell>
                    </TableRow>
                  ) : (
                    logs.map((log: BlockedCallLog) => (
                      <TableRow key={log.id}>
                        <TableCell className="font-mono">{log.caller_id}</TableCell>
                        <TableCell className="font-mono">{log.called_number}</TableCell>
                        <TableCell>
                          <Badge className={cn(getStrategyBadgeColor(log.rejection_strategy))}>
                            {strategyLabels[log.rejection_strategy]}
                          </Badge>
                        </TableCell>
                        <TableCell>
                          {log.inbound_blacklist?.caller_id_pattern || 'Unknown'}
                        </TableCell>
                        <TableCell>
                          {new Date(log.blocked_at).toLocaleString()}
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>

              {/* Pagination for logs */}
              {totalLogPages > 1 && (
                <div className="flex items-center justify-between mt-4 pt-4 border-t">
                  <div className="text-sm text-muted-foreground">
                    Page {logsPage} of {totalLogPages}
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setLogsPage(p => Math.max(1, p - 1))}
                      disabled={logsPage === 1}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setLogsPage(p => Math.min(totalLogPages, p + 1))}
                      disabled={logsPage === totalLogPages}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        {/* Statistics Tab */}
        <TabsContent value="stats">
          {stats && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <Card>
                <CardHeader>
                  <CardTitle>By Strategy</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-4">
                    <div className="flex justify-between items-center">
                      <span className="flex items-center gap-2">
                        <PhoneOff className="h-4 w-4 text-red-500" />
                        Drop (Silent)
                      </span>
                      <span className="font-semibold">{stats.by_strategy.drop}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="flex items-center gap-2">
                        <Phone className="h-4 w-4 text-orange-500" />
                        Reject (Message)
                      </span>
                      <span className="font-semibold">{stats.by_strategy.reject}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span className="flex items-center gap-2">
                        <Music className="h-4 w-4 text-purple-500" />
                        Torment (Music)
                      </span>
                      <span className="font-semibold">{stats.by_strategy.torment}</span>
                    </div>
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>By Match Type</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="space-y-4">
                    <div className="flex justify-between items-center">
                      <span>Exact Match</span>
                      <span className="font-semibold">{stats.by_match_type.exact}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span>Prefix Match</span>
                      <span className="font-semibold">{stats.by_match_type.prefix}</span>
                    </div>
                    <div className="flex justify-between items-center">
                      <span>Wildcard Pattern</span>
                      <span className="font-semibold">{stats.by_match_type.wildcard}</span>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </div>
          )}
        </TabsContent>
      </Tabs>

      {/* Create/Edit Dialog */}
      <Dialog open={isCreateDialogOpen || isEditDialogOpen} onOpenChange={(open) => {
        if (!open) {
          setIsCreateDialogOpen(false);
          setIsEditDialogOpen(false);
          setEditingItem(null);
          setFormData(emptyFormData);
          setFormErrors({});
        }
      }}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>
              {editingItem ? 'Edit Blacklist Entry' : 'Create Blacklist Entry'}
            </DialogTitle>
            <DialogDescription>
              {editingItem
                ? 'Update the blacklist entry settings'
                : 'Add a new caller ID pattern to the blacklist'}
            </DialogDescription>
          </DialogHeader>

          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="caller_id_pattern">Caller ID Pattern *</Label>
                <Input
                  id="caller_id_pattern"
                  value={formData.caller_id_pattern}
                  onChange={(e) => setFormData({ ...formData, caller_id_pattern: e.target.value })}
                  placeholder="+14155551234 or +1415*"
                  className={cn(formErrors.caller_id_pattern && "border-red-500")}
                />
                {formErrors.caller_id_pattern && (
                  <p className="text-sm text-red-500">{formErrors.caller_id_pattern}</p>
                )}
                <p className="text-xs text-muted-foreground">
                  E.164 format. Use * for wildcards (e.g., +1415* blocks all SF numbers)
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="match_type">Match Type *</Label>
                <Select
                  value={formData.match_type}
                  onValueChange={(v) => setFormData({ ...formData, match_type: v as InboundBlacklistMatchType })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="exact">Exact Match</SelectItem>
                    <SelectItem value="prefix">Prefix Match</SelectItem>
                    <SelectItem value="wildcard">Wildcard Pattern</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  How to match the caller ID pattern
                </p>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="description">Description</Label>
              <Textarea
                id="description"
                value={formData.description}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                placeholder="Why is this number being blocked?"
                rows={2}
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="rejection_strategy">Rejection Strategy *</Label>
                <Select
                  value={formData.rejection_strategy}
                  onValueChange={(v) => setFormData({ ...formData, rejection_strategy: v as InboundBlacklistRejectionStrategy })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="drop">Drop (Silent Hangup)</SelectItem>
                    <SelectItem value="reject">Reject (With Message)</SelectItem>
                    <SelectItem value="torment">Torment (Music Loop)</SelectItem>
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  How to handle calls from this number
                </p>
              </div>

              <div className="space-y-2">
                <Label htmlFor="status">Status</Label>
                <Select
                  value={formData.status}
                  onValueChange={(v) => setFormData({ ...formData, status: v as 'active' | 'inactive' })}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="inactive">Inactive</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            {formData.rejection_strategy === 'torment' && (
              <div className="grid grid-cols-2 gap-4 p-4 bg-purple-50 rounded-lg">
                <div className="space-y-2">
                  <Label htmlFor="torment_room_prefix">Room Prefix *</Label>
                  <Input
                    id="torment_room_prefix"
                    value={formData.torment_room_prefix}
                    onChange={(e) => setFormData({ ...formData, torment_room_prefix: e.target.value })}
                    placeholder="spam-trap"
                    className={cn(formErrors.torment_room_prefix && "border-red-500")}
                  />
                  {formErrors.torment_room_prefix && (
                    <p className="text-sm text-red-500">{formErrors.torment_room_prefix}</p>
                  )}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="torment_music_timeout">Timeout (seconds)</Label>
                  <Input
                    id="torment_music_timeout"
                    type="number"
                    min={60}
                    max={3600}
                    value={formData.torment_music_timeout}
                    onChange={(e) => setFormData({ ...formData, torment_music_timeout: parseInt(e.target.value) })}
                  />
                </div>
              </div>
            )}

            <div className="space-y-4 border-t pt-4">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label>Global Blacklist</Label>
                  <p className="text-xs text-muted-foreground">
                    Apply to all phone numbers in your organization
                  </p>
                </div>
                <Switch
                  checked={formData.is_global}
                  onCheckedChange={(checked) => setFormData({ ...formData, is_global: checked, did_number_id: checked ? null : formData.did_number_id })}
                />
              </div>

            {!formData.is_global && (
                <div className="space-y-2">
                  <Label htmlFor="did_number_id">Specific Phone Number</Label>
                  <Select
                    value={formData.did_number_id?.toString() || ''}
                    onValueChange={(v) => setFormData({ ...formData, did_number_id: parseInt(v) })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select a phone number" />
                    </SelectTrigger>
                    <SelectContent>
                      {phoneNumbers.map((did: DIDNumber) => (
                        <SelectItem key={did.id} value={did.id.toString()}>
                          {did.friendly_name || did.phone_number}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
            </div>

            <div className="space-y-2">
              <Label htmlFor="expires_at">Expiration Date (Optional)</Label>
              <Input
                id="expires_at"
                type="datetime-local"
                value={formData.expires_at}
                onChange={(e) => setFormData({ ...formData, expires_at: e.target.value })}
              />
              <p className="text-xs text-muted-foreground">
                Leave empty for permanent blacklist entry
              </p>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => {
                  setIsCreateDialogOpen(false);
                  setIsEditDialogOpen(false);
                  setEditingItem(null);
                }}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={createMutation.isPending || updateMutation.isPending}>
                {editingItem ? 'Update' : 'Create'}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation */}
      <AlertDialog open={!!deleteItem} onOpenChange={() => setDeleteItem(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Blacklist Entry</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete the blacklist entry for {' '}
              <span className="font-semibold">{deleteItem?.caller_id_pattern}</span>?
              This will remove the block and allow calls from this number.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => deleteItem && deleteMutation.mutate(deleteItem.id)}
              className="bg-red-600 hover:bg-red-700"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
};

export default InboundBlacklistPage;
