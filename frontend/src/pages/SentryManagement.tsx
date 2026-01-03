/**
 * Sentry Management Page
 * Handles Blacklists and individual blacklisted items (phone numbers)
 */

import { useState, useMemo } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { sentryService } from '@/services/sentry.service';
import { useAuth } from '@/hooks/useAuth';
import {
    Shield,
    Plus,
    Search,
    Edit,
    Trash2,
    ChevronRight,
    Loader2,
    Ban,
    MoreVertical,
    History,
    AlertTriangle,
    UserPlus,
} from 'lucide-react';
import { formatTimeAgo } from '@/utils/formatters';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import type { SentryBlacklist, Status } from '@/types/api.types';

export default function SentryManagement() {
    const queryClient = useQueryClient();
    const { user: currentUser } = useAuth();
    const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;

    // UI State
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedBlacklist, setSelectedBlacklist] = useState<SentryBlacklist | null>(null);
    const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
    const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);
    const [isAddItemDialogOpen, setIsAddItemDialogOpen] = useState(false);

    // Form State
    const [formData, setFormData] = useState({
        name: '',
        description: '',
        status: 'active' as Status,
    });
    const [itemFormData, setItemFormData] = useState({
        phone_number: '',
        reason: '',
        expires_at: '',
    });

    // Fetch Blacklists
    const { data: blacklistsResponse, isLoading } = useQuery({
        queryKey: ['sentry-blacklists'],
        queryFn: () => sentryService.listBlacklists(),
    });

    const blacklists = blacklistsResponse || [];

    // Mutations
    const createMutation = useMutation({
        mutationFn: sentryService.createBlacklist,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sentry-blacklists'] });
            setIsCreateDialogOpen(false);
            resetForm();
            toast.success('Blacklist created successfully');
        },
        onError: (error: any) => {
            toast.error(error.message || 'Failed to create blacklist');
        },
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, data }: { id: string; data: any }) => sentryService.updateBlacklist(id, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sentry-blacklists'] });
            setIsEditDialogOpen(false);
            toast.success('Blacklist updated successfully');
        },
        onError: (error: any) => {
            toast.error(error.message || 'Failed to update blacklist');
        },
    });

    const deleteMutation = useMutation({
        mutationFn: sentryService.deleteBlacklist,
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sentry-blacklists'] });
            setIsDeleteDialogOpen(false);
            toast.success('Blacklist deleted successfully');
        },
        onError: (error: any) => {
            toast.error(error.message || 'Failed to delete blacklist');
        },
    });

    const addItemMutation = useMutation({
        mutationFn: ({ blacklistId, data }: { blacklistId: string; data: any }) =>
            sentryService.addItem(blacklistId, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sentry-blacklist-details'] });
            setIsAddItemDialogOpen(false);
            setItemFormData({ phone_number: '', reason: '', expires_at: '' });
            toast.success('Number added to blacklist');
        },
        onError: (error: any) => {
            toast.error(error.message || 'Failed to add number');
        },
    });

    const removeItemMutation = useMutation({
        mutationFn: ({ blacklistId, itemId }: { blacklistId: string; itemId: string }) =>
            sentryService.removeItem(blacklistId, itemId),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sentry-blacklist-details'] });
            toast.success('Number removed from blacklist');
        },
    });

    // Event Handlers
    const handleCreate = () => {
        createMutation.mutate(formData);
    };

    const handleUpdate = () => {
        if (!selectedBlacklist) return;
        updateMutation.mutate({ id: selectedBlacklist.id, data: formData });
    };

    const handleDelete = () => {
        if (!selectedBlacklist) return;
        deleteMutation.mutate(selectedBlacklist.id);
    };

    const resetForm = () => {
        setFormData({ name: '', description: '', status: 'active' });
    };

    const openEdit = (blacklist: SentryBlacklist) => {
        setSelectedBlacklist(blacklist);
        setFormData({
            name: blacklist.name,
            description: blacklist.description || '',
            status: blacklist.status,
        });
        setIsEditDialogOpen(true);
    };

    const openDetails = (blacklist: SentryBlacklist) => {
        setSelectedBlacklist(blacklist);
        setIsDetailSheetOpen(true);
    };

    const handleAddItem = () => {
        if (!selectedBlacklist) return;
        addItemMutation.mutate({
            blacklistId: selectedBlacklist.id,
            data: itemFormData,
        });
    };

    // Filtered List
    const filteredBlacklists = useMemo(() => {
        if (!searchQuery) return blacklists;
        const q = searchQuery.toLowerCase();
        return blacklists.filter(
            (b) => b.name.toLowerCase().includes(q) || b.description?.toLowerCase().includes(q)
        );
    }, [blacklists, searchQuery]);

    return (
        <div className="container mx-auto py-8 px-4">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                <div>
                    <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-2">
                        <Shield className="h-8 w-8 text-primary" />
                        Routing Sentry
                    </h1>
                    <p className="mt-2 text-gray-600">
                        Manage list of blacklisted numbers and security protocols.
                    </p>
                </div>
                {canManage && (
                    <Button onClick={() => { resetForm(); setIsCreateDialogOpen(true); }}>
                        <Plus className="h-4 w-4 mr-2" />
                        New Blacklist
                    </Button>
                )}
            </div>

            {/* Search and Filters */}
            <div className="mb-6">
                <div className="relative max-w-sm">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-gray-400" />
                    <Input
                        placeholder="Search blacklists..."
                        className="pl-10"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                    />
                </div>
            </div>

            {/* Blacklists Grid */}
            {isLoading ? (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {[1, 2, 3].map((i) => (
                        <Card key={i} className="animate-pulse">
                            <CardHeader className="h-24 bg-gray-50 mb-4" />
                            <CardContent className="h-20 bg-gray-50" />
                        </Card>
                    ))}
                </div>
            ) : filteredBlacklists.length === 0 ? (
                <Card className="p-12 text-center">
                    <Ban className="h-12 w-12 text-gray-300 mx-auto mb-4" />
                    <h3 className="text-lg font-medium text-gray-900">No blacklists found</h3>
                    <p className="text-gray-500 mt-1">Start by creating your first routing security list.</p>
                </Card>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    {filteredBlacklists.map((blacklist) => (
                        <Card key={blacklist.id} className="hover:shadow-md transition-shadow">
                            <CardHeader className="pb-3">
                                <div className="flex justify-between items-start">
                                    <Badge variant={blacklist.status === 'active' ? 'default' : 'secondary'}>
                                        {blacklist.status}
                                    </Badge>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="ghost" size="sm" className="h-8 w-8 p-0">
                                                <MoreVertical className="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem onClick={() => openEdit(blacklist)}>
                                                <Edit className="h-4 w-4 mr-2" />
                                                Edit Settings
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                className="text-destructive"
                                                onClick={() => { setSelectedBlacklist(blacklist); setIsDeleteDialogOpen(true); }}
                                            >
                                                <Trash2 className="h-4 w-4 mr-2" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                                <CardTitle className="mt-2">{blacklist.name}</CardTitle>
                                <CardDescription className="line-clamp-2">
                                    {blacklist.description || 'No description provided.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center justify-between text-sm text-gray-500 mb-4">
                                    <div className="flex items-center gap-1">
                                        <History className="h-4 w-4" />
                                        <span>{blacklist.items_count || 0} numbers</span>
                                    </div>
                                    <span>Updated {formatTimeAgo(blacklist.updated_at)}</span>
                                </div>
                                <Button variant="outline" className="w-full" onClick={() => openDetails(blacklist)}>
                                    Manage Numbers
                                    <ChevronRight className="h-4 w-4 ml-2" />
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            )}

            {/* Detail Sheet: Blacklist Items */}
            <Sheet open={isDetailSheetOpen} onOpenChange={setIsDetailSheetOpen}>
                <SheetContent className="sm:max-w-xl overflow-y-auto">
                    <SheetHeader className="mb-6">
                        <SheetTitle>{selectedBlacklist?.name}</SheetTitle>
                        <SheetDescription>{selectedBlacklist?.description}</SheetDescription>
                    </SheetHeader>

                    <div className="space-y-6">
                        <div className="flex items-center justify-between">
                            <h3 className="text-lg font-semibold">Blacklisted Numbers</h3>
                            <Button size="sm" onClick={() => setIsAddItemDialogOpen(true)}>
                                <UserPlus className="h-4 w-4 mr-2" />
                                Add Number
                            </Button>
                        </div>

                        <BlacklistItemsTable
                            blacklistId={selectedBlacklist?.id}
                            onRemove={(itemId) => removeItemMutation.mutate({ blacklistId: selectedBlacklist!.id, itemId })}
                        />
                    </div>
                </SheetContent>
            </Sheet>

            {/* Create Dialog */}
            <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Create Blacklist</DialogTitle>
                        <DialogDescription>Define a new security list for call routing.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="create-name">Name</Label>
                            <Input
                                id="create-name"
                                value={formData.name}
                                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                placeholder="e.g., Global Spam Numbers"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="create-description">Description</Label>
                            <Input
                                id="create-description"
                                value={formData.description}
                                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                                placeholder="Brief description of this list..."
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsCreateDialogOpen(false)}>Cancel</Button>
                        <Button onClick={handleCreate} disabled={createMutation.isPending || !formData.name}>
                            {createMutation.isPending && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                            Create
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Edit Dialog */}
            <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit Blacklist</DialogTitle>
                        <DialogDescription>Update blacklist settings.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="edit-name">Name</Label>
                            <Input
                                id="edit-name"
                                value={formData.name}
                                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="edit-description">Description</Label>
                            <Input
                                id="edit-description"
                                value={formData.description}
                                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>Cancel</Button>
                        <Button onClick={handleUpdate} disabled={updateMutation.isPending || !formData.name}>
                            {updateMutation.isPending && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                            Save Changes
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Dialog */}
            <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-destructive">
                            <AlertTriangle className="h-5 w-5" />
                            Delete Blacklist
                        </DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete "{selectedBlacklist?.name}"? This action cannot be undone and will remove all associated numbers.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>Cancel</Button>
                        <Button variant="destructive" onClick={handleDelete} disabled={deleteMutation.isPending}>
                            {deleteMutation.isPending && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                            Delete Permanently
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Add Item Dialog */}
            <Dialog open={isAddItemDialogOpen} onOpenChange={setIsAddItemDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add Number to Blacklist</DialogTitle>
                        <DialogDescription>Add a specific number to block.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4 py-4">
                        <div className="space-y-2">
                            <Label htmlFor="item-phone">Phone Number</Label>
                            <Input
                                id="item-phone"
                                value={itemFormData.phone_number}
                                onChange={(e) => setItemFormData({ ...itemFormData, phone_number: e.target.value })}
                                placeholder="+1234567890"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="item-reason">Reason (Optional)</Label>
                            <Input
                                id="item-reason"
                                value={itemFormData.reason}
                                onChange={(e) => setItemFormData({ ...itemFormData, reason: e.target.value })}
                                placeholder="e.g., Fraudulent activity"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setIsAddItemDialogOpen(false)}>Cancel</Button>
                        <Button onClick={handleAddItem} disabled={addItemMutation.isPending || !itemFormData.phone_number}>
                            {addItemMutation.isPending && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
                            Add Number
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function BlacklistItemsTable({ blacklistId, onRemove }: { blacklistId?: string, onRemove: (id: string) => void }) {
    const { data: blacklist, isLoading } = useQuery({
        queryKey: ['sentry-blacklist-details', blacklistId],
        queryFn: () => sentryService.getBlacklist(blacklistId!),
        enabled: !!blacklistId,
    });

    if (isLoading) return <div className="space-y-2"><Skeleton className="h-10 w-full" /><Skeleton className="h-10 w-full" /></div>;

    const items = blacklist?.items || [];

    if (items.length === 0) {
        return (
            <div className="text-center py-8 border-2 border-dashed rounded-lg">
                <p className="text-gray-500">No numbers in this list yet.</p>
            </div>
        );
    }

    return (
        <div className="border rounded-md">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Number</TableHead>
                        <TableHead>Reason</TableHead>
                        <TableHead className="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {items.map((item) => (
                        <TableRow key={item.id}>
                            <TableCell className="font-mono">{item.phone_number}</TableCell>
                            <TableCell>{item.reason || '-'}</TableCell>
                            <TableCell className="text-right">
                                <Button variant="ghost" size="sm" onClick={() => onRemove(item.id)}>
                                    <Trash2 className="h-4 w-4 text-destructive" />
                                </Button>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
