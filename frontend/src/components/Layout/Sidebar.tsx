/**
 * Sidebar Navigation Component
 *
 * Main navigation sidebar with role-based menu items organized into sections
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
  Volume2,
  Menu,
  Shield,
  ShieldBan,
  Bot,
  Scale,
  Bell,
} from 'lucide-react';
import opbxLogo from '@/assets/opbx_logo.png';

interface NavItem {
  name: string;
  href: string;
  icon: React.ElementType;
  roles?: string[];
}

interface NavSection {
  title?: string; // undefined for top-level items like Dashboard
  items: NavItem[];
}

const navigation: NavSection[] = [
  // Top-level item (no section)
  {
    items: [
      { name: 'Dashboard', href: '/ui/dashboard', icon: LayoutDashboard },
    ],
  },
  // PBX Configuration
  {
    title: 'PBX Configuration',
    items: [
      { name: 'Users', href: '/ui/users', icon: Users, roles: ['owner', 'pbx_admin'] },
      { name: 'Extensions', href: '/ui/extensions', icon: Phone, roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'Conference Rooms', href: '/ui/conference-rooms', icon: Video, roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'Ring Groups', href: '/ui/ring-groups', icon: UserPlus, roles: ['owner', 'pbx_admin', 'reporter'] },
      { name: 'IVR Menus', href: '/ui/ivr-menus', icon: Menu, roles: ['owner', 'pbx_admin'] },
      { name: 'Business Hours', href: '/ui/business-hours', icon: Clock, roles: ['owner', 'pbx_admin'] },
      { name: 'Recordings', href: '/ui/recordings', icon: Volume2, roles: ['owner', 'pbx_admin'] },
      { name: 'Phone Numbers', href: '/ui/phone-numbers', icon: PhoneCall, roles: ['owner', 'pbx_admin', 'reporter'] },
      { name: 'Call Notifications', href: '/ui/call-notifications', icon: Bell, roles: ['owner', 'pbx_admin'] },
    ],
  },
  // AI Configuration
  {
    title: 'AI Configuration',
    items: [
      { name: 'AI Assistants', href: '/ui/ai-assistants', icon: Bot, roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'AI Load Balancers', href: '/ui/ai-assistant-load-balancers', icon: Scale, roles: ['owner', 'pbx_admin', 'reporter'] },
    ],
  },
  // Security
  {
    title: 'Security',
    items: [
      { name: 'Inbound Blacklist', href: '/ui/inbound-blacklist', icon: ShieldBan, roles: ['owner', 'pbx_admin'] },
      { name: 'Outbound Whitelist', href: '/ui/outbound-whitelist', icon: Shield, roles: ['owner'] },
    ],
  },
  // Monitoring
  {
    title: 'Monitoring',
    items: [
      { name: 'Live Calls', href: '/ui/live-calls', icon: Activity, roles: ['owner', 'pbx_admin', 'reporter'] },
      { name: 'Call Logs', href: '/ui/call-logs', icon: FileText, roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
    ],
  },
];

export function Sidebar() {
  const { user } = useAuth();

  // Filter navigation items based on user role
  const getVisibleItems = (items: NavItem[]) => {
    return items.filter((item) => {
      if (!item.roles) return true;
      return user?.role && item.roles.includes(user.role);
    });
  };

  return (
    <div className="flex h-full w-64 flex-col bg-gray-900 text-white">
      {/* Logo */}
      <div className="flex h-16 items-center justify-center px-6 border-b border-gray-800">
        <img src={opbxLogo} alt="OPBX Logo" className="h-18 w-auto" />
      </div>

      {/* Navigation */}
      <nav className="flex-1 space-y-4 px-3 py-4 overflow-y-auto">
        {navigation.map((section, sectionIndex) => {
          const visibleItems = getVisibleItems(section.items);
          
          // Don't render empty sections
          if (visibleItems.length === 0) return null;

          return (
            <div key={sectionIndex}>
              {/* Section Title */}
              {section.title && (
                <div className="px-3 mb-2">
                  <h3 className="text-xs font-semibold text-gray-400 uppercase tracking-wider">
                    {section.title}
                  </h3>
                </div>
              )}

              {/* Section Items */}
              <div className="space-y-1">
                {visibleItems.map((item) => (
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
              </div>
            </div>
          );
        })}
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
