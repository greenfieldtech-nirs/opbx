/**
 * ApiEndpointsInfo
 *
 * Side-by-side cards showing where the OPBX REST API and MCP server endpoints
 * live for this deployment. Values are derived from the application config
 * (request origin + configured MCP port), never hardcoded.
 */

import { Server, Bot, Copy } from 'lucide-react';
import { toast } from 'sonner';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

interface EndpointCardProps {
  icon: React.ReactNode;
  title: string;
  url: string;
  hint: React.ReactNode;
}

function EndpointCard({ icon, title, url, hint }: EndpointCardProps) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="flex items-center gap-2 text-sm">
          {icon}
          {title}
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-2">
        <div className="flex items-center gap-2">
          <code className="flex-1 truncate rounded bg-muted px-2 py-1 text-xs">{url}</code>
          <CopyButton value={url} label={`${title} URL`} />
        </div>
        <p className="text-xs text-muted-foreground">{hint}</p>
      </CardContent>
    </Card>
  );
}

export function ApiEndpointsInfo() {
  const { config } = useConfig();
  const endpoints = config?.endpoints;

  if (!endpoints) {
    return null;
  }

  return (
    <div className="grid gap-4 md:grid-cols-2">
      <EndpointCard
        icon={<Server className="h-4 w-4" />}
        title="REST API"
        url={endpoints.api_base_url}
        hint={
          <>
            Base URL for REST calls. Use an API key as{' '}
            <code>Authorization: Bearer &lt;key&gt;</code>.
          </>
        }
      />
      <EndpointCard
        icon={<Bot className="h-4 w-4" />}
        title="MCP Server"
        url={endpoints.mcp_url}
        hint="Point MCP clients (Claude, MCP Inspector) at this URL with the same Bearer credential."
      />
    </div>
  );
}

export default ApiEndpointsInfo;
