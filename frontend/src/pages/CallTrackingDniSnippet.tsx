import { useState, useMemo } from 'react';
import { Check, Copy, Code } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';

export default function CallTrackingDniSnippet() {
  const [apiUrl, setApiUrl] = useState(
    typeof window !== 'undefined'
      ? `${window.location.origin}/api/v1/call-tracking-dni/swap`
      : 'https://your-domain.com/api/v1/call-tracking-dni/swap'
  );
  const [copied, setCopied] = useState(false);

  const snippet = useMemo(
    () =>
      `\u003cscript\u003e
  (function() {
    var phoneElements = document.querySelectorAll('[data-ct-phone]');
    if (phoneElements.length === 0) return;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '${apiUrl}?source=' + encodeURIComponent(document.referrer || 'direct'));
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onload = function() {
      if (xhr.status !== 200) return;
      try {
        var response = JSON.parse(xhr.responseText);
        if (!response.phone_number) return;
        phoneElements.forEach(function(el) {
          el.textContent = response.phone_number;
          if (el.tagName === 'A') {
            el.href = 'tel:' + response.phone_number.replace(/\\D/g, '');
          }
        });
      } catch (e) {
        console.error('Call tracking DNI swap failed', e);
      }
    };
    xhr.send();
  })();
\u003c/script\u003e`,
    [apiUrl]
  );

  const handleCopy = async () => {
    await navigator.clipboard.writeText(snippet);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="p-6 space-y-6 max-w-4xl">
      <div className="flex items-center gap-2">
        <Code className="h-6 w-6" />
        <h1 className="text-2xl font-bold">DNI Snippet</h1>
      </div>

      <Alert>
        <AlertDescription>
          Add this snippet to your website to dynamically swap phone numbers based on the visitor's source.
          Mark any element with <code className="bg-muted px-1 rounded">data-ct-phone</code> and the script will replace its content.
        </AlertDescription>
      </Alert>

      <Card>
        <CardHeader>
          <CardTitle>Configuration</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="api-url">API URL</Label>
            <Input id="api-url" value={apiUrl} onChange={(e) => setApiUrl(e.target.value)} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>JavaScript Snippet</CardTitle>
          <Button variant="outline" size="sm" onClick={handleCopy}>
            {copied ? <Check className="h-4 w-4 mr-2" /> : <Copy className="h-4 w-4 mr-2" />}
            {copied ? 'Copied' : 'Copy'}
          </Button>
        </CardHeader>
        <CardContent>
          <Textarea value={snippet} readOnly rows={20} className="font-mono text-sm" />
        </CardContent>
      </Card>
    </div>
  );
}
