/**
 * Operate-As Banner
 *
 * Persistent full-width banner shown while a platform owner is operating as an
 * organization. Follows the null-or-render pattern of RefreshTimerBar. Renders
 * nothing when no operate-as session is active.
 */

import { useNavigate } from 'react-router-dom';
import { AlertTriangle } from 'lucide-react';
import { useOperateAs } from '@/context/OperateAsContext';
import { Button } from '@/components/ui/button';

export function OperateAsBanner() {
  const { isOperatingAs, operateAsOrg, exit } = useOperateAs();
  const navigate = useNavigate();

  if (!isOperatingAs || !operateAsOrg) {
    return null;
  }

  const handleExit = async () => {
    await exit();
    navigate('/ui/platform/organizations');
  };

  return (
    <div className="flex items-center justify-between gap-4 border-b border-yellow-200 bg-yellow-100 px-6 py-2 text-yellow-800">
      <div className="flex items-center gap-2">
        <AlertTriangle className="h-4 w-4 flex-shrink-0" />
        <span className="text-sm font-medium">
          Operating as {operateAsOrg.name} — you are acting as an organization admin.
        </span>
      </div>
      <Button
        variant="outline"
        size="sm"
        onClick={handleExit}
        className="border-yellow-300 bg-yellow-50 text-yellow-900 hover:bg-yellow-200"
      >
        Exit organization
      </Button>
    </div>
  );
}
