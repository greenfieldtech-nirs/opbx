/**
 * Sidebar Navigation Component
 *
 * Main navigation sidebar with role-based menu items
 */

import { NavLink } from 'react-router-dom';
import { cn } from '@/lib/utils';
import { useAuth } from '@/hooks/useAuth';
import {
  LayoutDashboard,
  Users,
  Phone,
  PhoneCall,
  UserPlus,
  Clock,
  FileText,
  Activity,
  Video,
} from 'lucide-react';
import opbxLogo from '@/assets/opbx_logo.png';

interface NavItem {
  name: string;
  href: string;
  icon: React.ElementType;
  roles?: string[];
}

const navigation: NavItem[] = [
  { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
  { name: 'Users', href: '/users', icon: Users, roles: ['owner', 'pbx_admin'] },
  { name: 'Extensions', href: '/extensions', icon: Phone },
  { name: 'Conference Rooms', href: '/conference-rooms', icon: Video },
  { name: 'Phone Numbers', href: '/dids', icon: PhoneCall, roles: ['owner', 'pbx_admin'] },
  { name: 'Ring Groups', href: '/ring-groups', icon: UserPlus },
  { name: 'Business Hours', href: '/business-hours', icon: Clock },
  { name: 'Call Logs', href: '/call-logs', icon: FileText },
  { name: 'Live Calls', href: '/live-calls', icon: Activity },
];

export function Sidebar() {
  const { user } = useAuth();

  // Filter navigation items based on user role
  const visibleNavigation = navigation.filter((item) => {
    if (!item.roles) return true;
    return user?.role && item.roles.includes(user.role);
  });

  return (
    <div className="flex h-full w-64 flex-col bg-gray-900 text-white">
      {/* Logo */}
      <div className="flex h-16 items-center justify-center px-6 border-b border-gray-800">
        <img src={opbxLogo} alt="OPBX Logo" className="h-18 w-auto" />
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
        {visibleNavigation.map((item) => (
          <NavLink
            key={item.name}
            to={item.href}
            className={({ isActive }) =>
              cn(
                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                isActive
                  ? 'bg-blue-600 text-white'
                  : 'text-gray-300 hover:bg-gray-800 hover:text-white'
              )
            }
          >
            <item.icon className="h-5 w-5" />
            {item.name}
          </NavLink>
        ))}
      </nav>

      {/* Footer */}
      <div className="border-t border-gray-800 p-4">
        <div className="text-xs text-gray-400">
          <p className="font-medium text-gray-300">{user?.name}</p>
          <p>{user?.email}</p>
          <p className="mt-1 capitalize">{user?.role} Account</p>
        </div>
      </div>
    </div>
  );
}
