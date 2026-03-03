/**
 * Platform Layout
 *
 * Layout wrapper for platform pages with breadcrumb and visual indicator.
 */

import { useLocation } from 'react-router-dom';
import { Building2 } from 'lucide-react';

interface PlatformLayoutProps {
  children: React.ReactNode;
}

export function PlatformLayout({ children }: PlatformLayoutProps) {
  const location = useLocation();
  const pageName = getPageName(location.pathname);

  return (
    <div className="min-h-screen">
      {/* Visual indicator for platform context */}
      <div className="bg-gradient-to-r from-blue-600/10 to-purple-600/10 border-b">
        <div className="container mx-auto px-4 py-3">
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Building2 className="h-4 w-4" />
            <span>Platform</span>
            <span>/</span>
            <span className="text-foreground font-medium">{pageName}</span>
          </div>
        </div>
      </div>

      <div className="container mx-auto px-4 py-6">
        {children}
      </div>
    </div>
  );
}

function getPageName(pathname: string): string {
  const segments = pathname.split('/');
  const lastSegment = segments[segments.length - 1] || 'dashboard';
  
  const pageNames: Record<string, string> = {
    dashboard: 'Dashboard',
    organizations: 'Organizations',
    users: 'Users',
    'audit-logs': 'Activity Log',
  };

  return pageNames[lastSegment] || lastSegment.charAt(0).toUpperCase() + lastSegment.slice(1);
}
