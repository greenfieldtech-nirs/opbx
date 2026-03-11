/**
 * Platform Error Page Component
 *
 * Displays when user doesn't have platform manager access (403).
 */

import { ShieldAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useNavigate } from 'react-router-dom';

interface PlatformErrorPageProps {
  title?: string;
  message?: string;
}

export function PlatformErrorPage({
  title = 'Access Denied',
  message = 'You do not have platform manager privileges. Contact your system administrator if you believe this is an error.',
}: PlatformErrorPageProps) {
  const navigate = useNavigate();

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50">
      <div className="text-center max-w-md mx-auto px-6">
        <div className="w-20 h-20 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-6">
          <ShieldAlert className="h-10 w-10 text-red-600" />
        </div>

        <h1 className="text-2xl font-bold text-gray-900 mb-3">
          {title}
        </h1>

        <p className="text-gray-600 mb-8">
          {message}
        </p>

        <Button onClick={() => navigate('/ui/dashboard')}>
          Return to Dashboard
        </Button>
      </div>
    </div>
  );
}
