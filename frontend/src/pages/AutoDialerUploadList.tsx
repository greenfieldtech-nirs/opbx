import { useState, useCallback, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { ArrowLeft, Upload, FileSpreadsheet, AlertCircle, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { toast } from 'sonner';
import { useUploadCampaignList, useAutoDialerCampaign } from '@/hooks/useAutoDialerCampaigns';

export default function AutoDialerUploadList() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [listName, setListName] = useState('');
  const [isDragging, setIsDragging] = useState(false);
  const [listNameError, setListNameError] = useState('');

  const { data: campaign } = useAutoDialerCampaign(id || '');
  const uploadMutation = useUploadCampaignList();

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(true);
  }, []);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(false);
  }, []);

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragging(false);
    
    const droppedFile = e.dataTransfer.files[0];
    if (droppedFile && droppedFile.name.endsWith('.csv')) {
      setFile(droppedFile);
      if (!listName) {
        setListName(droppedFile.name.replace('.csv', ''));
      }
    } else {
      toast.error('Please upload a CSV file');
    }
  }, [listName]);

  const handleFileSelect = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0];
    if (selectedFile && selectedFile.name.endsWith('.csv')) {
      setFile(selectedFile);
      if (!listName) {
        setListName(selectedFile.name.replace('.csv', ''));
      }
    } else if (selectedFile) {
      toast.error('Please upload a CSV file');
    }
  }, [listName]);

  const handleUpload = async () => {
    if (!id || !file) return;

    // Validate list name is required
    if (!listName.trim()) {
      setListNameError('List name is required');
      toast.error('Please enter a list name');
      return;
    }

    try {
      await uploadMutation.mutateAsync({
        campaignId: id,
        file,
        name: listName.trim(),
      });
      toast.success('List uploaded successfully');
      navigate(`/ui/auto-dialer/${id}`);
    } catch (error: any) {
      toast.error(error?.response?.data?.message || 'Failed to upload list');
    }
  };

  const triggerFileInput = () => {
    fileInputRef.current?.click();
  };

  const clearFile = () => {
    setFile(null);
    setListName('');
  };

  return (
    <div className="container mx-auto p-6 max-w-2xl">
      {/* Header */}
      <div className="mb-6">
        <Button variant="ghost" size="sm" onClick={() => navigate(`/ui/auto-dialer/${id}`)} className="mb-2">
          <ArrowLeft className="h-4 w-4 mr-2" />
          Back to Campaign
        </Button>
        <h1 className="text-3xl font-bold tracking-tight">Upload Destination List</h1>
        <p className="text-muted-foreground mt-1">
          {campaign?.name}
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <FileSpreadsheet className="h-5 w-5" />
            CSV Upload
          </CardTitle>
          <CardDescription>
            Upload a CSV file containing phone numbers to dial. The file should have a column named 'phone' or 'phone_number'.
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {/* CSV Format Info */}
          <Alert>
            <AlertCircle className="h-4 w-4" />
            <AlertDescription>
              Expected CSV format: A column with phone numbers in E.164 format (e.g., +14155551212).
              Optional columns: name, description, priority
            </AlertDescription>
          </Alert>

          {/* List Name */}
          <div className="space-y-2">
            <Label htmlFor="listName">
              List Name <span className="text-red-500">*</span>
            </Label>
            <Input
              id="listName"
              placeholder="My Contact List"
              value={listName}
              onChange={(e) => {
                setListName(e.target.value);
                if (e.target.value.trim()) {
                  setListNameError('');
                }
              }}
              className={listNameError ? 'border-red-500' : ''}
            />
            {listNameError && (
              <p className="text-sm text-red-500">{listNameError}</p>
            )}
          </div>

          {/* File Upload Area */}
          {!file ? (
            <div
              onDragOver={handleDragOver}
              onDragLeave={handleDragLeave}
              onDrop={handleDrop}
              className={`
                border-2 border-dashed rounded-lg p-8 text-center cursor-pointer
                transition-colors duration-200
                ${isDragging ? 'border-primary bg-primary/5' : 'border-muted-foreground/25 hover:border-muted-foreground/50'}
              `}
            >
              <input
                ref={fileInputRef}
                type="file"
                accept=".csv"
                onChange={handleFileSelect}
                className="hidden"
                id="csv-upload"
              />
              <div className="cursor-pointer" onClick={triggerFileInput}>
                <Upload className="h-10 w-10 mx-auto mb-4 text-muted-foreground" />
                <p className="text-lg font-medium mb-1">Drop your CSV file here</p>
                <p className="text-sm text-muted-foreground mb-4">or click to browse</p>
                <Button type="button" variant="outline" onClick={(e) => { e.stopPropagation(); triggerFileInput(); }}>
                  Select File
                </Button>
              </div>
            </div>
          ) : (
            <div className="border rounded-lg p-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <FileSpreadsheet className="h-8 w-8 text-green-600" />
                  <div>
                    <p className="font-medium">{file.name}</p>
                    <p className="text-sm text-muted-foreground">
                      {(file.size / 1024).toFixed(1)} KB
                    </p>
                  </div>
                </div>
                <Button variant="ghost" size="sm" onClick={clearFile}>
                  <X className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}

          {/* Upload Button */}
          <div className="flex gap-3">
            <Button
              variant="outline"
              onClick={() => navigate(`/ui/auto-dialer/${id}`)}
              className="flex-1"
            >
              Cancel
            </Button>
            <Button
              onClick={handleUpload}
              disabled={!file || !listName.trim() || uploadMutation.isPending}
              className="flex-1"
            >
              {uploadMutation.isPending ? (
                <>
                  <span className="animate-spin mr-2">⟳</span>
                  Uploading...
                </>
              ) : (
                <>
                  <Upload className="h-4 w-4 mr-2" />
                  Upload List
                </>
              )}
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Instructions */}
      <Card className="mt-6">
        <CardHeader>
          <CardTitle>CSV Format Guide</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div>
            <h4 className="font-medium mb-2">Required Column</h4>
            <ul className="list-disc list-inside text-sm text-muted-foreground space-y-1">
              <li><code className="bg-muted px-1 rounded">phone</code> or <code className="bg-muted px-1 rounded">phone_number</code> - Phone number in E.164 format</li>
            </ul>
          </div>
          <div>
            <h4 className="font-medium mb-2">Optional Columns</h4>
            <ul className="list-disc list-inside text-sm text-muted-foreground space-y-1">
              <li><code className="bg-muted px-1 rounded">name</code> - Contact name</li>
              <li><code className="bg-muted px-1 rounded">description</code> - Contact description</li>
              <li><code className="bg-muted px-1 rounded">priority</code> - Priority (1-10, higher = dialed first)</li>
            </ul>
          </div>
          <div>
            <h4 className="font-medium mb-2">Example CSV</h4>
            <pre className="bg-muted p-3 rounded text-sm overflow-x-auto">
{`phone,name,description,priority
+14155551212,John Doe,Sales Lead,5
+14155551213,Jane Smith,Customer,3
+14155551214,Bob Johnson,Hot Lead,10`}
            </pre>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
