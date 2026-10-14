import { useState } from 'react';
import { Download, Eye, Search, X } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDownloadListVersion, useListVersionDestinations, useListVersions } from '@/hooks/useDistributionLists';
import type { ListVersion } from '@/types';

const formatDate = (value: string | null) => (value ? new Date(value).toLocaleDateString() : '-');

interface ListVersionHistoryProps {
  listId: string;
  listName: string;
  canDownload: boolean;
}

/**
 * Previous versions of a list (the entries each re-upload replaced): browse, search and download
 * them until they are deleted after the retention period.
 */
export function ListVersionHistory({ listId, listName, canDownload }: ListVersionHistoryProps) {
  const { data, isLoading } = useListVersions(listId);
  const [selected, setSelected] = useState<ListVersion | null>(null);
  const downloadMutation = useDownloadListVersion();

  const versions = data?.data ?? [];
  const retentionDays = data?.meta.retention_days;

  const handleDownload = async (version: ListVersion) => {
    try {
      const blob = await downloadMutation.mutateAsync({ listId, versionId: version.id });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${listName} (version ${version.version_number}).csv`;
      a.click();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Failed to download version');
    }
  };

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>Version History</CardTitle>
          <CardDescription>
            Each time you upload a new file to this list, the entries it replaces are kept here as a previous
            version.{' '}
            {retentionDays
              ? `Previous versions are kept for ${retentionDays} days.`
              : 'Previous versions are kept until the list is deleted.'}
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Version</TableHead>
                <TableHead>Uploaded</TableHead>
                <TableHead>File</TableHead>
                <TableHead>Entries</TableHead>
                <TableHead>Replaced</TableHead>
                <TableHead>Deleted On</TableHead>
                <TableHead className="w-[160px]">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center py-8">Loading versions...</TableCell>
                </TableRow>
              ) : versions.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center py-8 text-muted-foreground">
                    No previous versions
                  </TableCell>
                </TableRow>
              ) : (
                versions.map((version) => (
                  <TableRow key={version.id} data-state={selected?.id === version.id ? 'selected' : undefined}>
                    <TableCell>v{version.version_number}</TableCell>
                    <TableCell>
                      <p>{formatDate(version.uploaded_at)}</p>
                      {version.uploaded_by && (
                        <p className="text-xs text-muted-foreground">by {version.uploaded_by.name}</p>
                      )}
                    </TableCell>
                    <TableCell className="max-w-[200px] truncate">{version.original_filename || '-'}</TableCell>
                    <TableCell>
                      <p>{version.entries_count.toLocaleString()}</p>
                      {version.valid_rows !== null && version.invalid_rows !== null && (
                        <p className="text-xs text-muted-foreground">
                          {version.valid_rows} valid, {version.invalid_rows} invalid
                        </p>
                      )}
                    </TableCell>
                    <TableCell>{formatDate(version.replaced_at)}</TableCell>
                    <TableCell>{version.expires_at ? formatDate(version.expires_at) : 'Kept'}</TableCell>
                    <TableCell>
                      <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => setSelected(version)}>
                          <Eye className="h-4 w-4 mr-1" />
                          View
                        </Button>
                        {canDownload && (
                          <Button
                            variant="outline"
                            size="sm"
                            aria-label={`Download version ${version.version_number}`}
                            onClick={() => handleDownload(version)}
                            disabled={downloadMutation.isPending}
                          >
                            <Download className="h-4 w-4" />
                          </Button>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {selected && (
        <VersionEntries
          key={selected.id}
          listId={listId}
          version={selected}
          canDownload={canDownload}
          downloading={downloadMutation.isPending}
          onDownload={() => handleDownload(selected)}
          onClose={() => setSelected(null)}
        />
      )}
    </div>
  );
}

interface VersionEntriesProps {
  listId: string;
  version: ListVersion;
  canDownload: boolean;
  downloading: boolean;
  onDownload: () => void;
  onClose: () => void;
}

function VersionEntries({ listId, version, canDownload, downloading, onDownload, onClose }: VersionEntriesProps) {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const { data, isLoading } = useListVersionDestinations(listId, version.id, {
    page,
    per_page: 25,
    search: search || undefined,
  });
  const entries = data?.data ?? [];
  const meta = data?.meta;

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
        <div className="space-y-1.5">
          <CardTitle>Version {version.version_number}</CardTitle>
          <CardDescription>
            The entries as they were when this version was replaced on {formatDate(version.replaced_at)}.
          </CardDescription>
        </div>
        <div className="flex gap-2">
          {canDownload && (
            <Button variant="outline" size="sm" onClick={onDownload} disabled={downloading}>
              <Download className="h-4 w-4 mr-2" />
              Download CSV
            </Button>
          )}
          <Button variant="ghost" size="icon" aria-label="Close version" onClick={onClose}>
            <X className="h-4 w-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent className="space-y-4 p-0">
        <div className="relative px-6">
          <Search className="absolute left-9 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Search phone numbers or names..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
            className="pl-9 h-10"
          />
        </div>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Phone Number</TableHead>
              <TableHead>Name</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Dial Attempts</TableHead>
              <TableHead>Last Result</TableHead>
              <TableHead>Last Dialed</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center py-8">Loading entries...</TableCell>
              </TableRow>
            ) : entries.length === 0 ? (
              <TableRow>
                <TableCell colSpan={6} className="text-center py-8 text-muted-foreground">No entries found</TableCell>
              </TableRow>
            ) : (
              entries.map((entry) => (
                <TableRow key={entry.id}>
                  <TableCell className="font-mono text-sm">{entry.phone_number}</TableCell>
                  <TableCell className="max-w-[200px]">{entry.name || '-'}</TableCell>
                  <TableCell>
                    <Badge variant="outline">{entry.status_label}</Badge>
                  </TableCell>
                  <TableCell>{entry.dial_attempts}</TableCell>
                  <TableCell>{entry.last_disposition || '-'}</TableCell>
                  <TableCell>{entry.last_dialed_at ? new Date(entry.last_dialed_at).toLocaleString() : '-'}</TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>

        {meta && meta.last_page > 1 && (
          <div className="p-4 border-t flex justify-center gap-2">
            <Button variant="outline" size="sm" onClick={() => setPage(page - 1)} disabled={page === 1}>
              Previous
            </Button>
            <span className="px-4 py-2 text-sm">
              Page {page} of {meta.last_page}
            </span>
            <Button variant="outline" size="sm" onClick={() => setPage(page + 1)} disabled={page === meta.last_page}>
              Next
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
