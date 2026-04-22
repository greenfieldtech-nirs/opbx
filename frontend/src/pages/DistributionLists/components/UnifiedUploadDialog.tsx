import { useState, useRef, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Upload, FileSpreadsheet, X, CheckCircle, AlertCircle, AlertTriangle } from 'lucide-react';
import { useUploadList, useUploadProgress } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';
import type { AutoDialerList } from '@/types';

interface UnifiedUploadDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: (newListId?: number) => void;
}

export function UnifiedUploadDialog({
  list,
  open,
  onOpenChange,
  onSuccess,
}: UnifiedUploadDialogProps) {
  const [file, setFile] = useState<File | null>(null);
  const [jobId, setJobId] = useState<string | null>(null);
  const [uploadResult, setUploadResult] = useState<{
    action?: 'upload' | 'update';
    listId?: number;
    newVersionNumber?: number;
  } | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const autoCloseTimerRef = useRef<NodeJS.Timeout | null>(null);

  const uploadMutation = useUploadList();
  const { data: progressData } = useUploadProgress(jobId);

  // Determine if this will create a new version
  const willCreateVersion = !list.status?.match(/^(draft|pending|failed)$/);
  const newVersionNumber = list.version_number + 1;

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
      });
      
      setJobId(result.data.job_id);
      setUploadResult({
        action: result.data.action,
        listId: result.data.list_id,
        newVersionNumber: result.data.new_version_number,
      });
      
      toast.success(
        result.data.action === 'update'
          ? `Version ${result.data.new_version_number} created. Old data backed up. Processing...`
          : 'Upload started. Processing...'
      );
    } catch {
      toast.error('Failed to start upload');
    }
  };

  // Auto-close on completion and trigger success callback
  useEffect(() => {
    const status = progressData?.data?.status;

    if (status === 'completed' && uploadResult && !autoCloseTimerRef.current) {
      // Immediately trigger success callback so parent refreshes the list
      if (onSuccess) {
        onSuccess(uploadResult.listId || list.id);
      }
      // Set a timer to auto-close after 2 seconds
      autoCloseTimerRef.current = setTimeout(() => {
        handleClose(false); // Don't call onSuccess again, already done above
      }, 2000);
    }

    return () => {
      if (autoCloseTimerRef.current) {
        clearTimeout(autoCloseTimerRef.current);
      }
    };
  }, [progressData?.data?.status, uploadResult]);

  const handleClose = (triggerSuccess = false) => {
    // Call onSuccess callback if explicitly requested (e.g. manual close after completion)
    if (triggerSuccess && onSuccess) {
      onSuccess(uploadResult?.listId || list.id);
    }

    // Reset state
    setFile(null);
    setJobId(null);
    setUploadResult(null);
    if (autoCloseTimerRef.current) {
      clearTimeout(autoCloseTimerRef.current);
      autoCloseTimerRef.current = null;
    }
    onOpenChange(false);
  };

  const isComplete = progressData?.data?.status === 'completed';
  const isFailed = progressData?.data?.status === 'failed' || 
                   progressData?.data?.status === 'error' || 
                   progressData?.data?.status === 'validation_failed';
  const progress = progressData?.data?.percentage || 0;

  return (
    <Dialog open={open} onOpenChange={(value) => !value && handleClose(false)}>
      <DialogContent className="sm:max-w-[500px]">
        <DialogHeader>
          <DialogTitle>Upload Destinations</DialogTitle>
          <DialogDescription>
            Upload a CSV file with phone numbers to &quot;{list.name}&quot;
          </DialogDescription>
        </DialogHeader>

        {/* Version Creation Warning */}
        {willCreateVersion && !jobId && (
          <Alert className="bg-amber-50 border-amber-200">
            <AlertTriangle className="h-4 w-4 text-amber-600" />
            <AlertDescription className="text-amber-800">
              Current version (v{list.version_number}) will be archived and version {newVersionNumber} will be created. 
              This action cannot be undone.
            </AlertDescription>
          </Alert>
        )}

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
                    CSV must have a &quot;phone_number&quot; column
                  </p>
                </button>
              )}
            </div>

            <Alert>
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>
                Maximum 100,000 rows per upload. Optional &quot;description&quot; column supported.
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
                  {uploadResult?.action === 'update'
                    ? `Version ${uploadResult.newVersionNumber} created and uploaded successfully! Old data backed up.`
                    : 'Upload completed successfully! Refreshing...'}
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
          <Button 
            variant="outline" 
            onClick={() => handleClose(false)}
            disabled={uploadMutation.isPending || (!isComplete && !isFailed && jobId !== null)}
          >
            {isComplete || isFailed ? 'Close' : 'Cancel'}
          </Button>
          {!jobId && (
            <Button onClick={handleUpload} disabled={!file || uploadMutation.isPending}>
              {uploadMutation.isPending ? 'Starting...' : willCreateVersion ? 'Create Version & Upload' : 'Start Upload'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default UnifiedUploadDialog;
