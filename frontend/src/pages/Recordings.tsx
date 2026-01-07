/**
 * Recordings Page
 */

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Database, Download, Eye, Pause, Play, Plus, Search, Trash2, Upload, Loader2, Filter, X } from 'lucide-react';
import { formatDateTime } from '@/utils/formatters';
import { recordingsService } from '@/services/createResourceService';
import { storage } from '@/utils/storage';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import { cn } from '@/lib/utils';

export default function Recordings() {
  const [recordingsPage, setRecordingsPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showFilters, setShowFilters] = useState(false);

  // Form state for filters
  const [filterForm, setFilterForm] = useState({
    search: '',
    type: '',
    status: '',
  });

  // Audio playback state
  const [currentlyPlaying, setCurrentlyPlaying] = useState<number | null>(null);
  const [audioElement, setAudioElement] = useState<HTMLAudioElement | null>(null);

  // Cleanup audio when component unmounts
  useEffect(() => {
    return () => {
      if (audioElement) {
        audioElement.pause();
        audioElement.src = '';
        setAudioElement(null);
      }
    };
  }, []); // Only run on unmount

  const [showUploadDialog, setShowUploadDialog] = useState(false);
  const [showRemoteDialog, setShowRemoteDialog] = useState(false);
  const [selectedRecording, setSelectedRecording] = useState<any>(null);

  const queryClient = useQueryClient();

  // Fetch recordings with filters
  const {
    data: recordingsData,
    isLoading: recordingsIsLoading,
    refetch: refetchRecordings,
  } = useQuery({
    queryKey: ['recordings', recordingsPage, filterForm],
    queryFn: () => recordingsService.getAll({
      ...filterForm,
      search: filterForm.search || undefined,
      type: filterForm.type !== 'all' ? filterForm.type : undefined,
      status: filterForm.status !== 'all' ? filterForm.status : undefined,
      page: recordingsPage,
    }),
  });

  // Create recording mutation
  const createRecordingMutation = useMutation({
    mutationFn: recordingsService.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['recordings'] });
      setShowUploadDialog(false);
      setShowRemoteDialog(false);
      toast.success('Recording created successfully');
    },
    onError: (error: any) => {
      toast.error('Failed to create recording: ' + error.message);
    },
  });

   // Download recording mutation
   const downloadRecordingMutation = useMutation({
     mutationFn: async (recording: any) => {
       // Step 1: Get the secure download URL and filename from API
       const response = await fetch(`/api/v1/recordings/${recording.id}/download`, {
      headers: {
        'Authorization': `Bearer ${storage.getToken()}`,
      },
       });

       if (!response.ok) {
         throw new Error('Failed to get download URL');
       }

       const data = await response.json();

       // Step 2: Download from the secure MinIO URL
       const downloadResponse = await fetch(data.download_url);
       if (!downloadResponse.ok) {
         throw new Error('Failed to download file from storage');
       }

       const blob = await downloadResponse.blob();

       // Step 3: Create download with correct filename
       const url = window.URL.createObjectURL(blob);
       const a = document.createElement('a');
       a.href = url;
       a.download = data.filename;
       document.body.appendChild(a);
       a.click();
       window.URL.revokeObjectURL(url);
       document.body.removeChild(a);
     },
     onError: (error: any) => {
       toast.error('Failed to download recording: ' + error.message);
     },
   });

  // Delete recording mutation
  const deleteRecordingMutation = useMutation({
    mutationFn: recordingsService.delete,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['recordings'] });
      toast.success('Recording deleted successfully');
    },
    onError: (error: any) => {
      toast.error('Failed to delete recording: ' + error.message);
    },
  });

  const handleDownload = (recording: any) => {
    downloadRecordingMutation.mutate(recording);
  };

  const handleDelete = (recording: any) => {
    if (confirm(`Are you sure you want to delete "${recording.name}"? This action cannot be undone.`)) {
      deleteRecordingMutation.mutate(recording.id);
    }
  };

  const handlePlayback = async (recording: any) => {
    if (currentlyPlaying === recording.id) {
      // Currently playing this recording, pause it
      if (audioElement) {
        audioElement.pause();
        setCurrentlyPlaying(null);
        setAudioElement(null);
      }
    } else {
      // Start playing this recording
      try {
        let audioSrc = '';

        if (recording.type === 'upload') {
          // For uploaded files, use the API stream endpoint
          audioSrc = recording.playback_url;
        } else {
          // For remote files, use remote URL directly
          audioSrc = recording.remote_url;
        }

        // Stop any currently playing audio
        if (audioElement) {
          audioElement.pause();
          audioElement.src = ''; // Clear source to free resources
          setAudioElement(null);
        }

        const audio = new Audio(audioSrc);

        audio.addEventListener('ended', () => {
          setCurrentlyPlaying(null);
          setAudioElement(null);
        });

        audio.addEventListener('error', () => {
          // Only show error if audio is not paused and we're still trying to play this recording
          if (!audio.paused && currentlyPlaying === recording.id) {
            toast.error('Failed to play recording');
          }
          setCurrentlyPlaying(null);
          setAudioElement(null);
        });

        setAudioElement(audio);

        await audio.play();
        setCurrentlyPlaying(recording.id);
        setAudioElement(audio);
      } catch (error) {
        toast.error('Failed to start playback');
      }
    }
  };

  const handleApplyFilters = () => {
    const newFilters: any = { page: 1 };
    if (filterForm.search) newFilters.search = filterForm.search;
    if (filterForm.type) newFilters.type = filterForm.type;
    if (filterForm.status) newFilters.status = filterForm.status;

    setFilterForm(newFilters);
    setRecordingsPage(1);
  };

  const handleClearFilters = () => {
    setFilterForm({
      search: '',
      type: '',
      status: '',
    });
    setRecordingsPage(1);
    setShowFilters(false);
  };

  if (recordingsData?.data === undefined) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-center">
          <Loader2 className="h-8 w-8 animate-spin mx-auto mb-2" />
          <p className="text-muted-foreground">Loading recordings...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Database className="h-8 w-8" />
            Recordings
          </h1>
          <p className="text-muted-foreground mt-1">Manage audio files for IVR and announcements</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Recordings</span>
          </div>
        </div>
      </div>

      {/* Recordings Section */}
      <Card>
        <CardHeader>
          <div className="flex justify-between items-start">
            <div>
              <CardTitle>Recordings</CardTitle>
              <CardDescription>
                {recordingsData?.meta?.total || 0} total recordings
              </CardDescription>
            </div>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => refetchRecordings()}
                disabled={recordingsIsLoading}
              >
                <Loader2 className={cn('h-4 w-4 mr-2', recordingsIsLoading && 'animate-spin')} />
                Refresh
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setShowFilters(!showFilters)}
              >
                <Filter className="h-4 w-4 mr-2" />
                {showFilters ? 'Hide Filters' : 'Show Filters'}
              </Button>
              <Button onClick={() => setShowRemoteDialog(true)} variant="outline" size="sm">
                <Plus className="h-4 w-4 mr-2" />
                Remote URL
              </Button>
              <Button onClick={() => setShowUploadDialog(true)} size="sm">
                <Upload className="h-4 w-4 mr-2" />
                Upload File
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {/* Filters */}
          {showFilters && (
            <div className="mb-6 p-4 bg-gray-50 rounded-lg border">
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <Label htmlFor="filter-search">Search</Label>
                  <div className="relative">
                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
                    <Input
                      id="filter-search"
                      placeholder="Search recordings..."
                      value={filterForm.search}
                      onChange={(e) => setFilterForm({ ...filterForm, search: e.target.value })}
                      className="pl-9"
                    />
                  </div>
                </div>
                <div>
                  <Label htmlFor="filter-type">Type</Label>
                  <Select
                    value={filterForm.type || 'all'}
                    onValueChange={(value) => setFilterForm({ ...filterForm, type: value })}
                  >
                    <SelectTrigger id="filter-type">
                      <SelectValue placeholder="All types" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All Types</SelectItem>
                      <SelectItem value="upload">Uploaded</SelectItem>
                      <SelectItem value="remote">Remote</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label htmlFor="filter-status">Status</Label>
                  <Select
                    value={filterForm.status || 'all'}
                    onValueChange={(value) => setFilterForm({ ...filterForm, status: value })}
                  >
                    <SelectTrigger id="filter-status">
                      <SelectValue placeholder="All status" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All Status</SelectItem>
                      <SelectItem value="active">Active</SelectItem>
                      <SelectItem value="inactive">Inactive</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
              <div className="flex gap-2 mt-4">
                <Button onClick={handleApplyFilters} size="sm">
                  <Filter className="h-4 w-4 mr-2" />
                  Apply Filters
                </Button>
                <Button onClick={handleClearFilters} variant="outline" size="sm">
                  <X className="h-4 w-4 mr-2" />
                  Clear Filters
                </Button>
              </div>
            </div>
          )}

          {/* Recordings List */}
          {recordingsIsLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <>
              <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                {recordingsData?.data?.length === 0 ? (
                  <div className="col-span-full text-center py-12">
                    <Database className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <h3 className="text-lg font-semibold mb-2">No recordings found</h3>
                    <p className="text-muted-foreground mb-4">
                      {Object.keys(filterForm).length > 1
                        ? 'Try adjusting your filters'
                        : 'Get started by uploading a file or adding a remote URL'}
                    </p>
                    {!filterForm.search && !filterForm.type && !filterForm.status && (
                      <div className="flex gap-2 justify-center">
                        <Button onClick={() => setShowUploadDialog(true)} variant="outline">
                          <Upload className="h-4 w-4 mr-2" />
                          Upload File
                        </Button>
                        <Button onClick={() => setShowRemoteDialog(true)}>
                          <Plus className="h-4 w-4 mr-2" />
                          Remote URL
                        </Button>
                      </div>
                    )}
                  </div>
                ) : (
                  recordingsData?.data?.map((recording: any) => (
                    <Card key={recording.id} className="hover:shadow-md transition-shadow">
                      <CardHeader className="pb-3">
                        <div className="flex items-start justify-between">
                          <div className="flex-1">
                            <CardTitle className="text-lg truncate">{recording.name}</CardTitle>
                            <CardDescription className="flex items-center gap-2 mt-1">
                              {recording.type === 'upload' ? (
                                <Badge variant="secondary">📁 Local</Badge>
                              ) : (
                                <Badge variant="outline">🔗 Remote</Badge>
                              )}
                            </CardDescription>
                          </div>
                          <Badge
                            variant={recording.status === 'active' ? 'default' : 'secondary'}
                            className="ml-2"
                          >
                            {recording.status}
                          </Badge>
                        </div>
                      </CardHeader>

                      <CardContent className="pt-0">
                        <div className="space-y-3">
                          <div className="text-sm text-muted-foreground">
                            <div>Created: {formatDateTime(recording.created_at)}</div>
                            <div>By: {recording.created_by}</div>
                          </div>

                          <div className="flex gap-2">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handlePlayback(recording)}
                            >
                              {currentlyPlaying === recording.id ? (
                                <>
                                  <Pause className="h-3 w-3 mr-1" />
                                  Pause
                                </>
                              ) : (
                                <>
                                  <Play className="h-3 w-3 mr-1" />
                                  Play
                                </>
                              )}
                            </Button>

                            {recording.type === 'upload' && (
                              <Button
                                size="sm"
                                variant="outline"
                                onClick={() => handleDownload(recording)}
                                disabled={downloadRecordingMutation.isPending}
                              >
                                <Download className="h-3 w-3 mr-1" />
                                Download
                              </Button>
                            )}

                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => setSelectedRecording(recording)}
                            >
                              <Eye className="h-3 w-3 mr-1" />
                              Details
                            </Button>

                            <Button
                              size="sm"
                              variant="destructive"
                              onClick={() => handleDelete(recording)}
                              disabled={deleteRecordingMutation.isPending}
                            >
                              <Trash2 className="h-3 w-3 mr-1" />
                              Delete
                            </Button>
                          </div>
                        </div>
                      </CardContent>
                    </Card>
                  ))
                )}
              </div>

              {/* Pagination */}
              {recordingsData && recordingsData.data.length > 0 && (
                <div className="flex items-center justify-between mt-4 pt-4 border-t">
                  <div className="text-sm text-muted-foreground">
                    Showing {recordingsData.meta?.from || 0} to {recordingsData.meta?.to || 0} of{' '}
                    {recordingsData.meta?.total} recordings
                  </div>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setRecordingsPage(recordingsPage - 1)}
                      disabled={recordingsPage === 1}
                    >
                      Previous
                    </Button>
                    <div className="flex items-center px-3 text-sm">
                      Page {recordingsData.meta?.current_page} of {recordingsData.meta?.last_page}
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setRecordingsPage(recordingsPage + 1)}
                      disabled={recordingsPage >= (recordingsData.meta?.last_page || 1)}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {/* Upload Dialog */}
      <Dialog open={showUploadDialog} onOpenChange={setShowUploadDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Upload Recording</DialogTitle>
            <DialogDescription>
              Upload an MP3 or WAV file (max 5MB)
            </DialogDescription>
          </DialogHeader>

          <UploadForm
            onSubmit={(data) => createRecordingMutation.mutate(data)}
            onCancel={() => setShowUploadDialog(false)}
            isLoading={createRecordingMutation.isPending}
          />
        </DialogContent>
      </Dialog>

      {/* Remote URL Dialog */}
      <Dialog open={showRemoteDialog} onOpenChange={setShowRemoteDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Add Remote Recording</DialogTitle>
            <DialogDescription>
              Add a recording from a remote URL (HTTP/HTTPS)
            </DialogDescription>
          </DialogHeader>

          <RemoteUrlForm
            onSubmit={(data) => createRecordingMutation.mutate(data)}
            onCancel={() => setShowRemoteDialog(false)}
            isLoading={createRecordingMutation.isPending}
          />
        </DialogContent>
      </Dialog>

      {/* Details Dialog */}
      <Dialog open={!!selectedRecording} onOpenChange={() => setSelectedRecording(null)}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle>{selectedRecording?.name}</DialogTitle>
            <DialogDescription>
              Recording details and metadata
            </DialogDescription>
          </DialogHeader>

          {selectedRecording && (
            <RecordingDetails recording={selectedRecording} />
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}

// Type definitions
interface FormProps {
  onSubmit: (data: any) => void;
  onCancel: () => void;
  isLoading: boolean;
}

// Upload Form Component
function UploadForm({ onSubmit, onCancel, isLoading }: FormProps) {
  const [name, setName] = useState('');
  const [file, setFile] = useState<File | null>(null);

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file || !name.trim()) return;

    const formData = new FormData();
    formData.append('name', name);
    formData.append('type', 'upload');
    formData.append('file', file);

    onSubmit(formData);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-2">Recording Name</label>
        <Input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Enter recording name"
          required
        />
      </div>

      <div>
        <label className="block text-sm font-medium mb-2">Audio File</label>
        <Input
          type="file"
          accept=".mp3,.wav"
          onChange={(e) => setFile(e.target.files?.[0] || null)}
          required
        />
        <p className="text-xs text-muted-foreground mt-1">
          Supported formats: MP3, WAV (max 5MB)
        </p>
      </div>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={isLoading || !file || !name.trim()}>
          {isLoading && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
          Upload
        </Button>
      </div>
    </form>
  );
}

// Remote URL Form Component
function RemoteUrlForm({ onSubmit, onCancel, isLoading }: FormProps) {
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!url || !name.trim()) return;

    onSubmit({
      name,
      type: 'remote',
      remote_url: url,
    });
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div>
        <label className="block text-sm font-medium mb-2">Recording Name</label>
        <Input
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Enter recording name"
          required
        />
      </div>

      <div>
        <label className="block text-sm font-medium mb-2">Remote URL</label>
        <Input
          type="url"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          placeholder="https://example.com/audio.mp3"
          required
        />
        <p className="text-xs text-muted-foreground mt-1">
          URL must be accessible and point to an MP3 or WAV file
        </p>
      </div>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button type="submit" disabled={isLoading || !url || !name.trim()}>
          {isLoading && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
          Add Recording
        </Button>
      </div>
    </form>
  );
}

// Recording Details Component
function RecordingDetails({ recording }: { recording: any }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
        <div className="min-w-0">
          <div className="text-sm font-medium text-muted-foreground">Type</div>
          <p className="capitalize text-base break-words">{recording.type}</p>
        </div>
        <div className="min-w-0">
          <div className="text-sm font-medium text-muted-foreground">Status</div>
          <Badge variant={recording.status === 'active' ? 'default' : 'secondary'}>
            {recording.status}
          </Badge>
        </div>

        <div className="min-w-0">
          <div className="text-sm font-medium text-muted-foreground">MIME Type</div>
          <p className="text-base break-words">{recording.mime_type || 'Unknown'}</p>
        </div>
        <div className="min-w-0">
          <div className="text-sm font-medium text-muted-foreground">Created</div>
          <p className="text-base break-words">{formatDateTime(recording.created_at)}</p>
        </div>
      </div>

      {recording.type === 'remote' && (
        <div>
          <label className="block text-sm font-medium text-muted-foreground mb-2">Remote URL</label>
          <a
            href={recording.remote_url}
            target="_blank"
            rel="noopener noreferrer"
            className="text-blue-600 hover:text-blue-800 break-all"
          >
            {recording.remote_url}
          </a>
        </div>
      )}

      {recording.type === 'upload' && (
        <div>
          <label className="block text-sm font-medium text-muted-foreground mb-2">Original Filename</label>
          <p className="break-all">{recording.original_filename || 'Unknown'}</p>
        </div>
      )}
    </div>
  );
}
