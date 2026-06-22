import { useState, useMemo } from 'react';
import { Check, Copy, Code } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';

function jsStringLiteral(value: string): string {
  return JSON.stringify(value).replace(/\u003c/g, '\\u003c');
}

export default function CallTrackingDniSnippet() {
  const [apiUrl, setApiUrl] = useState(
    typeof window !== 'undefined'
      ? `${window.location.origin}/api/v1/call-tracking-dni/swap`
      : 'https://your-domain.com/api/v1/call-tracking-dni/swap'
  );
  const [defaultNumber, setDefaultNumber] = useState('');
  const [organizationId, setOrganizationId] = useState('');
  const [copied, setCopied] = useState(false);

  const isOrgIdValid = organizationId === '' || /^\d+$/.test(organizationId);
  const isDefaultNumberValid = defaultNumber === '' || /^\+[1-9]\d{1,14}$/.test(defaultNumber);
  const canCopy = isOrgIdValid && isDefaultNumberValid;

  const literalApiUrl = useMemo(() => jsStringLiteral(apiUrl), [apiUrl]);
  const literalDefaultNumber = useMemo(
    () => jsStringLiteral(defaultNumber),
    [defaultNumber]
  );
  const literalOrganizationId = useMemo(
    () => jsStringLiteral(organizationId),
    [organizationId]
  );

  const snippet = useMemo(
    () =>
      `\u003cscript\u003e
  (function() {
    var phoneElements = document.querySelectorAll('[data-ct-phone]');
    if (phoneElements.length === 0) return;

    var pageQuery = new URLSearchParams(window.location.search);
    var utmSource = pageQuery.get('utm_source') || (document.referrer || 'direct');
    var utmMedium = pageQuery.get('utm_medium');
    var utmCampaign = pageQuery.get('utm_campaign');

    var params = [];
    if (${literalOrganizationId}) params.push('organization_id=' + encodeURIComponent(${literalOrganizationId}));
    if (${literalDefaultNumber}) params.push('default_number=' + encodeURIComponent(${literalDefaultNumber}));
    if (utmSource) params.push('utm_source=' + encodeURIComponent(utmSource));
    if (utmMedium) params.push('utm_medium=' + encodeURIComponent(utmMedium));
    if (utmCampaign) params.push('utm_campaign=' + encodeURIComponent(utmCampaign));

    var xhr = new XMLHttpRequest();
    xhr.open('GET', ${literalApiUrl} + (params.length ? '?' + params.join('&') : ''));
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onload = function() {
      if (xhr.status !== 200) return;
      try {
        var response = JSON.parse(xhr.responseText);
        if (!response.tracking_number) return;
        phoneElements.forEach(function(el) {
          el.textContent = response.tracking_number;
          if (el.tagName === 'A') {
            el.href = 'tel:' + response.tracking_number.replace(/\\D/g, '');
          }
        });
      } catch (e) {
        console.error('Call tracking DNI swap failed', e);
      }
    };
    xhr.onerror = function() {
      console.error('Call tracking DNI swap request failed');
    };
    xhr.send();
  })();
\u003c/script\u003e`,
    [
      literalApiUrl,
      literalDefaultNumber,
      literalOrganizationId,
    ]
  );

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(snippet);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      toast.error('Failed to copy snippet to clipboard');
    }
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
          <div className="space-y-2">
            <Label htmlFor="default-number">Default Number</Label>
            <Input
              id="default-number"
              value={defaultNumber}
              onChange={(e) => setDefaultNumber(e.target.value)}
              placeholder="+1234567890"
            />
            {defaultNumber !== '' && !isDefaultNumberValid && (
              <p className="text-sm text-red-600">Default number must be in E.164 format (e.g. +14155551234).</p>
            )}
          </div>
          <div className="space-y-2">
            <Label htmlFor="organization-id">Organization ID</Label>
            <Input
              id="organization-id"
              value={organizationId}
              onChange={(e) => setOrganizationId(e.target.value)}
              placeholder="123"
            />
            {organizationId !== '' && !isOrgIdValid && (
              <p className="text-sm text-red-600">Organization ID must be numeric.</p>
            )}
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>JavaScript Snippet</CardTitle>
          <Button variant="outline" size="sm" onClick={handleCopy} disabled={!canCopy}>
            {copied ? <Check className="h-4 w-4 mr-2" /> : <Copy className="h-4 w-4 mr-2" />}
            {copied ? 'Copied' : 'Copy'}
          </Button>
        </CardHeader>
        <CardContent>
          <Textarea value={snippet} readOnly rows={24} className="font-mono text-sm" />
        </CardContent>
      </Card>
    </div>
  );
}
