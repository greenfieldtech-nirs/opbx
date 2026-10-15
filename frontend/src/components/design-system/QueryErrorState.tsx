/**
 * QueryErrorState Component
 *
 * Shared error state for list and detail pages whose main query failed.
 * Shows a message and, when `onRetry` is given, a Retry button.
 *
 * @example
 * if (isError) return <QueryErrorState title="Failed to load ring groups" error={error} onRetry={() => refetch()} />;
 */

import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { getApiErrorMessage } from '@/services/api';

interface QueryErrorStateProps {
  title?: string;
  error?: unknown;
  onRetry?: () => void;
}

export function QueryErrorState({ title = 'Failed to load data', error, onRetry }: QueryErrorStateProps) {
  return (
    <div role="alert" className="text-center py-12">
      <AlertTriangle className="h-8 w-8 text-destructive mx-auto mb-3" aria-hidden="true" />
      <p className="text-destructive font-medium mb-2">{title}</p>
      <p className="text-muted-foreground text-sm">{error ? getApiErrorMessage(error) : 'An error occurred'}</p>
      {onRetry && (
        <Button onClick={onRetry} className="mt-4">
          <RefreshCw className="h-4 w-4 mr-2" />
          Retry
        </Button>
      )}
    </div>
  );
}
