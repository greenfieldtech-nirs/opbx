/**
 * ApiEndpointsInfo
 *
 * Information box showing where the OPBX REST API and MCP server endpoints
 * live for this deployment. Values are derived from the application config
 * (request origin + configured MCP port), never hardcoded.
 */

import { Server, Bot, Copy } from 'lucide-react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useConfig } from '@/context/ConfigContext';

function CopyButton({ value, label }: { value: string; label: string }) {
  return (
    <Button
      type="button"
      variant="ghost"
      size="icon"
      className="h-7 w-7 shrink-0"
      aria-label={`Copy ${label}`}
      onClick={() => {
        navigator.clipboard.writeText(value);
        toast.success(`${label} copied to clipboard`);
      }}
    >
      <Copy className="h-3.5 w-3.5" />
    </Button>
  );
}

export function ApiEndpointsInfo() {
  const { config } = useConfig();
  const endpoints = config?.endpoints;

  if (!endpoints) {
    return null;
  }

  return (
    <Alert>
      <Server className="h-4 w-4" />
      <AlertTitle>API & MCP endpoints</AlertTitle>
      <AlertDescription>
        <div className="mt-2 space-y-2">
          <div className="flex items-center gap-2">
            <code className="flex-1 truncate rounded bg-muted px-2 py-1 text-xs">
              {endpoints.api_base_url}
            </code>
            <CopyButton value={endpoints.api_base_url} label="REST API base URL" />
          </div>
          <p className="text-xs text-muted-foreground">
            Use an API key as <code>Authorization: Bearer &lt;key&gt;</code> for REST calls.
          </p>
          <div className="flex items-center gap-2">
            <Bot className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <code className="flex-1 truncate rounded bg-muted px-2 py-1 text-xs">
              {endpoints.mcp_url}
            </code>
            <CopyButton value={endpoints.mcp_url} label="MCP endpoint URL" />
          </div>
          <p className="text-xs text-muted-foreground">
            Point MCP clients (Claude, MCP Inspector) at this URL with the same Bearer credential.
          </p>
        </div>
      </AlertDescription>
    </Alert>
  );
}

export default ApiEndpointsInfo;
