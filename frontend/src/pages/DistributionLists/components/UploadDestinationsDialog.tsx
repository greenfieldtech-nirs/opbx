import { useState, useRef } from 'react';
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
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Upload, FileSpreadsheet, X, CheckCircle, AlertCircle } from 'lucide-react';
import { useUploadList, useUploadProgress } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';
import type { AutoDialerList } from '@/types';

interface UploadDestinationsDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}

export function UploadDestinationsDialog({
  list,
  open,
  onOpenChange,
  onSuccess,
}: UploadDestinationsDialogProps) {
  const [file, setFile] = useState<File | null>(null);
  const [jobId, setJobId] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const uploadMutation = useUploadList();
  const { data: progressData } = useUploadProgress(jobId);

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0];
    if (selectedFile) {
      if (selectedFile.name.endsWith('.csv')) {
        setFile(selectedFile);
      } else {
        toast.error('Please upload a CSV file');
      }
    }
  };

  const handleUpload = async () => {
    if (!file) return;

    try {
      const result = await uploadMutation.mutateAsync({
        listId: list.id,
        file,
        mapping: { phone: 'phone_number' },
      });
      setJobId(result.data.job_id);
      toast.success('Upload started. Processing...');
    } catch {
      toast.error('Failed to start upload');
    }
  };

  const handleClose = () => {
    // Call onSuccess callback if upload completed or failed (to refresh the list)
    if ((isComplete || isFailed) && onSuccess) {
      onSuccess();
    }
    setFile(null);
    setJobId(null);
    onOpenChange(false);
  };

  const isComplete = progressData?.data?.status === 'completed';
  const isFailed = progressData?.data?.status === 'failed' || progressData?.data?.status === 'error' || progressData?.data?.status === 'validation_failed';
  const progress = progressData?.data?.percentage || 0;

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle>Upload Destinations</DialogTitle>
          <DialogDescription>
            Upload a CSV file with phone numbers to &quot;{list.name}&quot;
          </DialogDescription>
        </DialogHeader>

        {!jobId && (
          <>
            <div className="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv"
                onChange={handleFileSelect}
                className="hidden"
              />
              {file ? (
                <div className="flex items-center justify-center gap-2">
                  <FileSpreadsheet className="h-8 w-8 text-green-600" />
                  <div className="text-left">
                    <p className="font-medium">{file.name}</p>
                    <p className="text-sm text-muted-foreground">
                      {(file.size / 1024).toFixed(1)} KB
                    </p>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => setFile(null)}
                  >
                    <X className="h-4 w-4" />
                  </Button>
                </div>
              ) : (
                <button
                  onClick={() => fileInputRef.current?.click()}
                  className="w-full"
                >
                  <Upload className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <p className="text-lg font-medium">Click to select a CSV file</p>
                  <p className="text-sm text-muted-foreground mt-2">
                    or drag and drop (not supported)
                  </p>
                </button>
              )}
            </div>

            <Alert className="mt-4">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>
                CSV must have a &quot;phone_number&quot; column. Maximum 100,000 rows per upload.
              </AlertDescription>
            </Alert>
          </>
        )}

        {jobId && (
          <div className="space-y-4">
            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span>Status: {progressData?.data?.status}</span>
                <span>{progress}%</span>
              </div>
              <div className="w-full bg-gray-200 rounded-full h-2">
                <div
                  className="bg-blue-600 h-2 rounded-full transition-all"
                  style={{ width: `${progress}%` }}
                />
              </div>
            </div>

            {isComplete && (
              <Alert className="bg-green-50 border-green-200">
                <CheckCircle className="h-4 w-4 text-green-600" />
                <AlertDescription className="text-green-800">
                  Upload completed successfully!
                </AlertDescription>
              </Alert>
            )}

            {isFailed && (
              <Alert variant="destructive">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription>
                  {progressData?.data?.status === 'validation_failed'
                    ? 'CSV validation failed. Please check the file format and try again.'
                    : 'Upload failed. Please check the validation errors.'}
                </AlertDescription>
              </Alert>
            )}
          </div>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={handleClose}>
            {isComplete || isFailed ? 'Close' : 'Cancel'}
          </Button>
          {!jobId && (
            <Button onClick={handleUpload} disabled={!file || uploadMutation.isPending}>
              {uploadMutation.isPending ? 'Uploading...' : 'Start Upload'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
