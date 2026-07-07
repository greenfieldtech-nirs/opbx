import { useState, useRef, useEffect, useMemo } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Upload, FileSpreadsheet, X, CheckCircle, AlertCircle, AlertTriangle } from 'lucide-react';
import { useUploadList, useUploadProgress, usePreviewCsv } from '@/hooks/useDistributionLists';
import { toast } from 'sonner';
import axios from 'axios';
import type { AutoDialerList, CsvMappingConfig } from '@/types';

interface UnifiedUploadDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: (newListId?: number) => void;
}

const REQUIRED_FIELDS = [
  { key: 'phone', label: 'Phone Number *' },
  { key: 'name', label: 'Full Name' },
  { key: 'batch_identifier', label: 'Batch Identifier' },
];

function getUploadError(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; error?: string } | undefined;
    if (data?.message) {
      return data.message;
    }
    if (data?.error) {
      return data.error;
    }
    return error.message || 'An unexpected error occurred';
  }

  return 'An unexpected error occurred';
}

export function UnifiedUploadDialog({
  list,
  open,
  onOpenChange,
  onSuccess,
}: UnifiedUploadDialogProps) {
  const [file, setFile] = useState<File | null>(null);
  const [hasHeader, setHasHeader] = useState(true);
  const [preview, setPreview] = useState<{ headers: string[]; rows: Record<string, string>[]; total_rows: number } | null>(null);
  const [mapping, setMapping] = useState<CsvMappingConfig>({ phone: '', name: '', batch_identifier: '', metadata: [] });
  const [jobId, setJobId] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [uploadResult, setUploadResult] = useState<{
    action?: 'upload' | 'update';
    listId?: number;
    newVersionNumber?: number;
  } | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const autoCloseTimerRef = useRef<NodeJS.Timeout | null>(null);

  const uploadMutation = useUploadList();
  const previewMutation = usePreviewCsv();
  const { data: progressData } = useUploadProgress(jobId);

  const willCreateVersion = !list.status?.match(/^(draft|pending|failed)$/);
  const newVersionNumber = list.version_number + 1;

  const availableColumns = useMemo(() => preview?.headers ?? [], [preview]);

  const resetDialog = () => {
    setFile(null);
    setHasHeader(true);
    setPreview(null);
    setMapping({ phone: '', name: '', batch_identifier: '', metadata: [] });
    setJobId(null);
    setUploadResult(null);
    setError(null);
    if (autoCloseTimerRef.current) {
      clearTimeout(autoCloseTimerRef.current);
      autoCloseTimerRef.current = null;
    }
  };

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0];
    if (!selectedFile) return;
    if (!selectedFile.name.endsWith('.csv')) {
      setError('Please upload a CSV file');
      return;
    }
    setFile(selectedFile);
    setError(null);
    setPreview(null);
    setMapping({ phone: '', name: '', batch_identifier: '', metadata: [] });
  };

  const handlePreview = async () => {
    if (!file) return;
    setError(null);
    try {
      const result = await previewMutation.mutateAsync({ listId: list.id, file, hasHeader });
      setPreview(result.data);
      const headers = result.data.headers;
      const phoneGuess = headers.find((h) => h.toLowerCase().includes('phone') || h.toLowerCase() === 'phone_number') ?? '';
      const nameGuess = headers.find((h) => h.toLowerCase().includes('name') && h.toLowerCase() !== 'phone_number') ?? '';
      const batchGuess = headers.find((h) => h.toLowerCase().includes('batch')) ?? '';
      setMapping({ phone: phoneGuess, name: nameGuess, batch_identifier: batchGuess, metadata: [] });
    } catch (err) {
      setError(getUploadError(err));
    }
  };

  const handleUpload = async () => {
    if (!file || !mapping.phone) return;

    setError(null);

    const cleanedMapping: CsvMappingConfig = {
      phone: mapping.phone,
      ...(mapping.name ? { name: mapping.name } : {}),
      ...(mapping.batch_identifier ? { batch_identifier: mapping.batch_identifier } : {}),
      ...(mapping.metadata?.length ? { metadata: mapping.metadata } : {}),
    };

    try {
      const result = await uploadMutation.mutateAsync({
        listId: list.id,
        file,
        mapping: cleanedMapping,
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
    } catch (err) {
      setError(getUploadError(err));
    }
  };

  useEffect(() => {
    const status = progressData?.data?.status;

    if (status === 'completed' && uploadResult && !autoCloseTimerRef.current) {
      if (onSuccess) {
        onSuccess(uploadResult.listId || list.id);
      }
      autoCloseTimerRef.current = setTimeout(() => {
        handleClose(false);
      }, 2000);
    }

    return () => {
      if (autoCloseTimerRef.current) {
        clearTimeout(autoCloseTimerRef.current);
      }
    };
  }, [progressData?.data?.status, uploadResult]);

  const handleClose = (triggerSuccess = false) => {
    if (triggerSuccess && onSuccess) {
      onSuccess(uploadResult?.listId || list.id);
    }
    resetDialog();
    onOpenChange(false);
  };

  const isComplete = progressData?.data?.status === 'completed';
  const isFailed =
    progressData?.data?.status === 'failed' ||
    progressData?.data?.status === 'error' ||
    progressData?.data?.status === 'validation_failed';
  const progress = progressData?.data?.percentage || 0;

  const mappedPreview = useMemo(() => {
    if (!preview) return [];
    return preview.rows.map((row) => ({
      phone: mapping.phone ? row[mapping.phone] ?? '' : '',
      name: mapping.name ? row[mapping.name] ?? '' : '',
      batch: mapping.batch_identifier ? row[mapping.batch_identifier] ?? '' : '',
      metadata: mapping.metadata?.map((col) => `${col}: ${row[col] ?? ''}`).join(', ') ?? '',
    }));
  }, [preview, mapping]);

  return (
    <Dialog open={open} onOpenChange={(value) => !value && handleClose(false)}>
      <DialogContent className="sm:max-w-[700px]">
        <DialogHeader>
          <DialogTitle>Upload Destinations</DialogTitle>
          <DialogDescription>
            Upload a CSV file to &quot;{list.name}&quot; and map columns.
          </DialogDescription>
        </DialogHeader>

        {willCreateVersion && !jobId && (
          <Alert className="bg-amber-50 border-amber-200">
            <AlertTriangle className="h-4 w-4 text-amber-600" />
            <AlertDescription className="text-amber-800">
              Current version (v{list.version_number}) will be archived and version {newVersionNumber} will be created.
            </AlertDescription>
          </Alert>
        )}

        {error && !jobId && (
          <Alert variant="destructive">
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>{error}</AlertDescription>
          </Alert>
        )}

        {!jobId && !preview && (
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
                  <Button variant="ghost" size="icon" onClick={() => setFile(null)}>
                    <X className="h-4 w-4" />
                  </Button>
                </div>
              ) : (
                <button onClick={() => fileInputRef.current?.click()} className="w-full">
                  <Upload className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <p className="text-lg font-medium">Click to select a CSV file</p>
                  <p className="text-sm text-muted-foreground mt-2">
                    CSV must include a phone column
                  </p>
                </button>
              )}
            </div>

            <div className="flex items-center gap-2">
              <Checkbox
                id="has-header"
                checked={hasHeader}
                onCheckedChange={(checked) => setHasHeader(checked === true)}
              />
              <Label htmlFor="has-header">File has a header row</Label>
            </div>

            <Button onClick={handlePreview} disabled={!file || previewMutation.isPending}>
              {previewMutation.isPending ? 'Parsing...' : 'Continue to Mapping'}
            </Button>
          </>
        )}

        {!jobId && preview && (
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">{preview.total_rows.toLocaleString()} rows detected</p>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              {REQUIRED_FIELDS.map((field) => (
                <div key={field.key} className="space-y-2">
                  <Label>{field.label}</Label>
                  <Select
                    value={mapping[field.key as keyof CsvMappingConfig] as string}
                    onValueChange={(value) =>
                      setMapping((prev) => ({ ...prev, [field.key]: value === 'NONE' ? '' : value }))
                    }
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select column" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="NONE">None</SelectItem>
                      {availableColumns.map((col) => (
                        <SelectItem key={col} value={col}>
                          {col}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ))}
            </div>

            <div className="space-y-2">
              <Label>Metadata columns</Label>
              <div className="grid grid-cols-2 gap-2 border rounded p-3">
                {availableColumns.map((col) => (
                  <div key={col} className="flex items-center gap-2">
                    <Checkbox
                      id={`meta-${col}`}
                      checked={mapping.metadata?.includes(col) ?? false}
                      onCheckedChange={(checked) => {
                        setMapping((prev) => {
                          const current = prev.metadata ?? [];
                          const updated = checked
                            ? [...current, col]
                            : current.filter((c) => c !== col);
                          return { ...prev, metadata: updated };
                        });
                      }}
                    />
                    <Label htmlFor={`meta-${col}`} className="text-sm font-normal">{col}</Label>
                  </div>
                ))}
              </div>
            </div>

            {mappedPreview.length > 0 && (
              <div className="border rounded-md overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Phone</TableHead>
                      <TableHead>Name</TableHead>
                      <TableHead>Batch</TableHead>
                      <TableHead>Metadata</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {mappedPreview.slice(0, 3).map((row, idx) => (
                      <TableRow key={idx}>
                        <TableCell>{row.phone}</TableCell>
                        <TableCell>{row.name || '-'}</TableCell>
                        <TableCell>{row.batch || '-'}</TableCell>
                        <TableCell className="text-xs text-muted-foreground max-w-[200px] truncate">{row.metadata || '-'}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            )}

            <Button variant="outline" onClick={() => { setPreview(null); setError(null); }}>
              Back
            </Button>
          </div>
        )}

        {jobId && (
          <div className="space-y-4">
            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span>Status: {progressData?.data?.status}</span>
                <span>{progress}%</span>
              </div>
              <div className="w-full bg-gray-200 rounded-full h-2">
                <div className="bg-blue-600 h-2 rounded-full transition-all" style={{ width: `${progress}%` }} />
              </div>
            </div>
            {isComplete && (
              <Alert className="bg-green-50 border-green-200">
                <CheckCircle className="h-4 w-4 text-green-600" />
                <AlertDescription className="text-green-800">
                  {uploadResult?.action === 'update'
                    ? `Version ${uploadResult.newVersionNumber} created and uploaded successfully!`
                    : 'Upload completed successfully!'}
                </AlertDescription>
              </Alert>
            )}
            {isFailed && (
              <Alert variant="destructive">
                <AlertCircle className="h-4 w-4" />
                <AlertDescription>
                  {progressData?.data?.status === 'validation_failed'
                    ? 'CSV validation failed. Check the file format and mapping.'
                    : 'Upload failed. Check validation errors.'}
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
          {!jobId && preview && (
            <Button onClick={handleUpload} disabled={!mapping.phone || uploadMutation.isPending}>
              {uploadMutation.isPending ? 'Starting...' : willCreateVersion ? 'Create Version & Upload' : 'Start Upload'}
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export default UnifiedUploadDialog;
