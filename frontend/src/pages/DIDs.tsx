/**
 * DIDs (Phone Numbers) Page
 *
 * Manage inbound phone numbers and routing
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { didsService } from '@/services/dids.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Plus, PhoneCall, Search, Filter } from 'lucide-react';
import { formatPhoneNumber, getStatusColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';

export default function DIDs() {
  const [page, setPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [routingTypeFilter, setRoutingTypeFilter] = useState<string>('all');

  const { data, isLoading, error } = useQuery({
    queryKey: ['dids', page],
    queryFn: () => didsService.getAll({ page, per_page: 20 }),
  });

  // Filter data based on search and filters
  const filteredData = data?.data?.filter((did) => {
    const matchesSearch = searchQuery === '' ||
      did.did_number.includes(searchQuery) ||
      formatPhoneNumber(did.did_number).includes(searchQuery);

    const matchesStatus = statusFilter === 'all' || did.status === statusFilter;

    const matchesRoutingType = routingTypeFilter === 'all' || did.routing_type === routingTypeFilter;

    return matchesSearch && matchesStatus && matchesRoutingType;
  }) || [];

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <PhoneCall className="h-8 w-8" />
            Phone Numbers
          </h1>
          <p className="text-muted-foreground mt-1">Manage inbound phone numbers and routing</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Phone Numbers</span>
          </div>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          Add Phone Number
        </Button>
      </div>

      {/* Filters Section */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
            {/* Search */}
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search phone numbers..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            {/* Filter dropdowns */}
            <Select value={statusFilter} onValueChange={(value: any) => setStatusFilter(value)}>
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            <Select value={routingTypeFilter} onValueChange={setRoutingTypeFilter}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Routing Type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Types</SelectItem>
                <SelectItem value="extension">Extension</SelectItem>
                <SelectItem value="ring_group">Ring Group</SelectItem>
                <SelectItem value="ivr">IVR</SelectItem>
                <SelectItem value="voicemail">Voicemail</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Phone Numbers Card */}
      <Card>
        <CardHeader>
          <CardTitle>Phone Numbers</CardTitle>
          <CardDescription>
            {filteredData.length} {filteredData.length === 1 ? 'number' : 'numbers'} configured
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="text-center py-12">
              <p className="text-muted-foreground">Loading phone numbers...</p>
            </div>
          ) : error ? (
            <div className="text-center py-12 text-destructive">
              <p className="font-semibold mb-2">Error loading phone numbers</p>
              <p className="text-sm text-muted-foreground">Please try again later</p>
            </div>
          ) : filteredData.length === 0 ? (
            <div className="text-center py-12">
              <PhoneCall className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-medium mb-2">No phone numbers found</h3>
              <p className="text-muted-foreground mb-4">
                {searchQuery || statusFilter !== 'all' || routingTypeFilter !== 'all'
                  ? 'Try adjusting your filters'
                  : 'Get started by adding your first phone number'}
              </p>
              {!searchQuery && statusFilter === 'all' && routingTypeFilter === 'all' && (
                <Button>
                  <Plus className="h-4 w-4 mr-2" />
                  Add Phone Number
                </Button>
              )}
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Phone Number</TableHead>
                  <TableHead>Routing Type</TableHead>
                  <TableHead>Destination</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredData.map((did) => (
                  <TableRow key={did.id} className="hover:bg-gray-50">
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <PhoneCall className="h-5 w-5 text-blue-600" />
                        <span className="font-medium">{formatPhoneNumber(did.did_number)}</span>
                      </div>
                    </TableCell>
                    <TableCell className="capitalize">{did.routing_type.replace('_', ' ')}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {did.extension?.extension_number || did.ring_group?.name || 'N/A'}
                    </TableCell>
                    <TableCell>
                      <span className={cn('px-2 py-1 rounded-full text-xs font-medium', getStatusColor(did.status))}>
                        {did.status}
                      </span>
                    </TableCell>
                    <TableCell className="text-right">
                      <Button variant="ghost" size="sm">Edit</Button>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
