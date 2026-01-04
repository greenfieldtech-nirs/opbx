import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Database, Download, Eye, Pause, Play, Plus, Search, Trash2, Upload, Loader2 } from 'lucide-react';
import { formatDateTime } from '@/utils/formatters';
import api from '@/services/api';

// Type definitions
interface FormProps {
  onSubmit: (data: any) => void;
  onCancel: () => void;
  isLoading: boolean;
}
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';

// API service for recordings
const recordingsService = {
  getAll: async (params = {}) => {
    const response = await api.get('/recordings', { params });
    return response.data;
  },

  create: async (data: any) => {
    const response = await api.post('/recordings', data);
    return response.data;
  },

  update: async (id: number, data: any) => {
    const response = await api.put(`/recordings/${id}`, data);
    return response.data;
  },



  download: async (id: number) => {
    const response = await api.get(`/recordings/${id}/download`);
    return response.data;
  },

  delete: async (id: number) => {
    const response = await api.delete(`/recordings/${id}`);
    return response.data;
  },
};

export default function Recordings() {
  const [searchQuery, setSearchQuery] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  // Audio playback state
  const [currentlyPlaying, setCurrentlyPlaying] = useState<number | null>(null);
  const [audioElement, setAudioElement] = useState<HTMLAudioElement | null>(null);

  // Cleanup audio when component unmounts
  useEffect(() => {
    return () => {
      if (audioElement) {
        audioElement.pause();
        audioElement.src = '';
      }
    };
  }, [audioElement]);
  const [showUploadDialog, setShowUploadDialog] = useState(false);
  const [showRemoteDialog, setShowRemoteDialog] = useState(false);
  const [selectedRecording, setSelectedRecording] = useState<any>(null);

  const queryClient = useQueryClient();

  // Fetch recordings with filters
  const { data: recordingsData, isLoading, error } = useQuery({
    queryKey: ['recordings', searchQuery, typeFilter, statusFilter],
    queryFn: () => recordingsService.getAll({
      search: searchQuery || undefined,
      type: typeFilter !== 'all' ? typeFilter : undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
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
    onError: (error) => {
      toast.error('Failed to create recording: ' + error.message);
    },
  });



  // Download recording mutation
  const downloadRecordingMutation = useMutation({
    mutationFn: (id: number) => recordingsService.download(id),
    onSuccess: (data) => {
      window.open(data.download_url, '_blank');
    },
    onError: (error) => {
      toast.error('Failed to download recording: ' + error.message);
    },
  });

  // Delete recording mutation
  const deleteRecordingMutation = useMutation({
    mutationFn: (id: number) => recordingsService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['recordings'] });
      toast.success('Recording deleted successfully');
    },
    onError: (error) => {
      toast.error('Failed to delete recording: ' + error.message);
    },
  });



  const handleDownload = (recording: any) => {
    downloadRecordingMutation.mutate(recording.id);
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
          // For uploaded files, get the download URL
          const downloadResponse = await recordingsService.download(recording.id);
          audioSrc = downloadResponse.download_url;
        } else {
          // For remote files, use the remote URL directly
          audioSrc = recording.remote_url;
        }

        // Stop any currently playing audio
        if (audioElement) {
          audioElement.pause();
        }

        const audio = new Audio(audioSrc);
        audio.addEventListener('ended', () => {
          setCurrentlyPlaying(null);
          setAudioElement(null);
        });

        audio.addEventListener('error', () => {
          toast.error('Failed to play recording');
          setCurrentlyPlaying(null);
          setAudioElement(null);
        });

        await audio.play();
        setCurrentlyPlaying(recording.id);
        setAudioElement(audio);
      } catch (error) {
        toast.error('Failed to start playback');
      }
    }
  };

  if (error) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="text-center">
          <p className="text-red-600 mb-2">Error loading recordings</p>
          <p className="text-sm text-gray-600">{error.message}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">Recordings</h1>
          <p className="text-muted-foreground">
            Manage audio files for IVR and announcements
          </p>
        </div>

        <div className="flex gap-2">
          <Button onClick={() => setShowRemoteDialog(true)} variant="outline">
            <Plus className="h-4 w-4 mr-2" />
            Remote URL
          </Button>
          <Button onClick={() => setShowUploadDialog(true)}>
            <Upload className="h-4 w-4 mr-2" />
            Upload File
          </Button>
        </div>
      </div>

      {/* Filters */}
      <div className="flex gap-4">
        <div className="flex-1">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4" />
            <Input
              placeholder="Search recordings..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-9"
            />
          </div>
        </div>

        <Select value={typeFilter} onValueChange={setTypeFilter}>
          <SelectTrigger className="w-32">
            <SelectValue placeholder="Type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Types</SelectItem>
            <SelectItem value="upload">Uploaded</SelectItem>
            <SelectItem value="remote">Remote</SelectItem>
          </SelectContent>
        </Select>

        <Select value={statusFilter} onValueChange={setStatusFilter}>
          <SelectTrigger className="w-32">
            <SelectValue placeholder="Status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Status</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Recordings List */}
      {isLoading ? (
        <div className="flex items-center justify-center h-64">
          <Loader2 className="h-8 w-8 animate-spin" />
        </div>
      ) : (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {recordingsData?.data?.map((recording: any) => (
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
                      <span className="text-xs">
                        {recording.formatted_file_size || 'Unknown size'}
                      </span>
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
                    <div>Duration: {recording.formatted_duration || 'Unknown'}</div>
                    <div>Created: {formatDateTime(recording.created_at)}</div>
                    <div>By: {recording.created_by}</div>
                  </div>

                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => handlePlayback(recording)}
                      disabled={downloadRecordingMutation.isPending && recording.id === downloadRecordingMutation.variables}
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
          ))}

          {recordingsData?.data?.length === 0 && (
            <div className="col-span-full text-center py-12">
              <Database className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
              <h3 className="text-lg font-semibold mb-2">No recordings found</h3>
              <p className="text-muted-foreground mb-4">
                Get started by uploading a file or adding a remote URL.
              </p>
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
            </div>
          )}
        </div>
      )}

      {/* Pagination would go here if needed */}

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
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-sm font-medium text-muted-foreground">Type</label>
          <p className="capitalize">{recording.type}</p>
        </div>
        <div>
          <label className="block text-sm font-medium text-muted-foreground">Status</label>
          <Badge variant={recording.status === 'active' ? 'default' : 'secondary'}>
            {recording.status}
          </Badge>
        </div>
        <div>
          <label className="block text-sm font-medium text-muted-foreground">File Size</label>
          <p>{recording.formatted_file_size}</p>
        </div>
        <div>
          <label className="block text-sm font-medium text-muted-foreground">Duration</label>
          <p>{recording.formatted_duration}</p>
        </div>
        <div>
          <label className="block text-sm font-medium text-muted-foreground">MIME Type</label>
          <p>{recording.mime_type || 'Unknown'}</p>
        </div>
        <div>
          <label className="block text-sm font-medium text-muted-foreground">Created</label>
          <p>{formatDateTime(recording.created_at)}</p>
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
          <p>{recording.original_filename || 'Unknown'}</p>
        </div>
      )}
    </div>
  );
}