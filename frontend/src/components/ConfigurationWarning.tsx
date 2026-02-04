/**
 * Configuration Warning Banner
 *
 * Displays warnings about invalid application configuration
 * (e.g., production mode without webhook URL set)
 */

import { AlertTriangle, X } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useConfig } from '@/context/ConfigContext';
import { useState } from 'react';

export function ConfigurationWarning() {
  const { hasWarnings, warnings, isProduction } = useConfig();
  const [dismissed, setDismissed] = useState(false);

  // Don't show if no warnings or if dismissed
  if (!hasWarnings || dismissed) {
    return null;
  }

  return (
    <Alert variant="destructive" className="relative mb-4">
      <AlertTriangle className="h-4 w-4" />
      <AlertTitle>Configuration Warning</AlertTitle>
      <AlertDescription>
        <div className="space-y-2">
          {isProduction && (
            <p className="font-semibold">
              The application is running in production mode with invalid configuration:
            </p>
          )}
          <ul className="list-disc list-inside space-y-1">
            {warnings.map((warning, index) => (
              <li key={index}>{warning}</li>
            ))}
          </ul>
          <p className="text-sm mt-2">
            Please contact your system administrator to resolve this issue.
          </p>
        </div>
      </AlertDescription>
      <Button
        variant="ghost"
        size="icon"
        className="absolute top-2 right-2 h-6 w-6"
        onClick={() => setDismissed(true)}
      >
        <X className="h-4 w-4" />
      </Button>
    </Alert>
  );
}
