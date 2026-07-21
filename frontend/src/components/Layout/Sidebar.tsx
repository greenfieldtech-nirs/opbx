/**
 * VSCode-Style Activity Bar + Sidebar Navigation
 *
 * Two-panel layout with full-width logo header:
 * - Top: Full-width OPBX Logo only
 * - Left: Icon-only Activity Bar (56px) with main sections
 * - Right: Contextual sidebar with section title in header
 */

import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { NavLink, useLocation, useNavigate } from 'react-router-dom';
import { cn } from '@/lib/utils';
import { useAuth } from '@/hooks/useAuth';
import { storage } from '@/utils/storage';
import opbxLogo from '@/assets/opbx_logo.png';

// VSCode Codicons
import '@vscode/codicons/dist/codicon.css';

interface NavItem {
  name: string;
  href: string;
  icon: string;
  roles?: string[];
  isHeader?: boolean;
}

interface SidebarSection {
  id: string;
  title: string;
  icon: string;
  items: NavItem[];
  accentColor?: 'default' | 'amber';
  roles?: string[];
}

// Sidebar state storage keys
const SELECTED_SECTION_KEY = 'opbx_sidebar_selected_section';
const SIDEBAR_WIDTH_KEY = 'opbx_sidebar_width';

// Default values
const DEFAULT_SIDEBAR_WIDTH = 240;
const MIN_SIDEBAR_WIDTH = 200;
const MAX_SIDEBAR_WIDTH = 400;
const ACTIVITY_BAR_WIDTH = 56;
const LOGO_HEADER_HEIGHT = 96;

// Define all navigation sections
const sidebarSections: SidebarSection[] = [
  {
    id: 'dashboards',
    title: 'Dashboards',
    icon: 'codicon-home',
    accentColor: 'default',
    items: [
      { name: 'Dashboard', href: '/ui/dashboard', icon: 'codicon-dashboard' },
    ],
  },
  {
    id: 'pbx-config',
    title: 'PBX Configuration',
    icon: 'codicon-settings-gear',
    accentColor: 'default',
    items: [
      { name: 'Users', href: '/ui/users', icon: 'codicon-account', roles: ['owner', 'pbx_admin'] },
      { name: 'Supervisors', href: '/ui/supervisors', icon: 'codicon-shield', roles: ['owner', 'pbx_admin'] },
      { name: 'Extensions', href: '/ui/extensions', icon: 'codicon-extensions', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'Conference Rooms', href: '/ui/conference-rooms', icon: 'codicon-device-camera-video', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'Ring Groups', href: '/ui/ring-groups', icon: 'codicon-call-incoming', roles: ['owner', 'pbx_admin', 'reporter'] },
      { name: 'IVR Menus', href: '/ui/ivr-menus', icon: 'codicon-menu', roles: ['owner', 'pbx_admin'] },
      { name: 'Business Hours', href: '/ui/business-hours', icon: 'codicon-clock', roles: ['owner', 'pbx_admin'] },
      { name: 'Announcements', href: '/ui/announcements', icon: 'codicon-megaphone', roles: ['owner', 'pbx_admin'] },
      { name: 'Phone Numbers', href: '/ui/phone-numbers', icon: 'codicon-arrow-right', roles: ['owner', 'pbx_admin', 'reporter'] },
    ],
  },
  {
    id: 'apps-security',
    title: 'Apps and Security',
    icon: 'codicon-shield',
    accentColor: 'default',
    roles: ['owner', 'pbx_admin', 'reporter'],
    items: [
      { name: 'Auto Dialer', href: '', icon: '', isHeader: true },
      { name: 'Campaign Manager', href: '/ui/auto-dialer/campaigns', icon: 'codicon-target', roles: ['owner', 'pbx_admin'] },
      { name: 'Distribution Lists', href: '/ui/auto-dialer/distribution-lists', icon: 'codicon-list-unordered', roles: ['owner', 'pbx_admin'] },
      { name: 'Real Time Monitor', href: '/ui/auto-dialer/monitor', icon: 'codicon-pulse', roles: ['owner', 'pbx_admin'] },
      { name: 'AI Configuration', href: '', icon: '', isHeader: true },
      { name: 'AI Assistants', href: '/ui/ai-assistants', icon: 'codicon-copilot', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'AI Load Balancers', href: '/ui/ai-assistant-load-balancers', icon: 'codicon-layers', roles: ['owner', 'pbx_admin', 'reporter'] },
      { name: 'Call Tracking', href: '', icon: '', isHeader: true },
      { name: 'Dashboard', href: '/ui/call-tracking/dashboard', icon: 'codicon-dashboard', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'Campaigns', href: '/ui/call-tracking/campaigns', icon: 'codicon-target', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'Sessions', href: '/ui/call-tracking/sessions', icon: 'codicon-call-incoming', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'DNI Snippet', href: '/ui/call-tracking/dni-snippet', icon: 'codicon-code', roles: ['owner', 'pbx_admin'] },
      { name: 'Integrations', href: '/ui/call-tracking/integrations', icon: 'codicon-plug', roles: ['owner'] },
      { name: 'Security', href: '', icon: '', isHeader: true },
      { name: 'Inbound Blacklist', href: '/ui/inbound-blacklist', icon: 'codicon-circle-slash', roles: ['owner', 'pbx_admin'] },
      { name: 'Outbound Whitelist', href: '/ui/outbound-whitelist', icon: 'codicon-pass', roles: ['owner'] },
    ],
  },
  {
    id: 'analytics',
    title: 'Analytics',
    icon: 'codicon-graph',
    accentColor: 'default',
    items: [
      { name: 'Live Calls', href: '/ui/live-calls', icon: 'codicon-debug-rerun', roles: ['owner', 'pbx_admin', 'reporter'] },
      { name: 'Call Logs', href: '/ui/call-logs', icon: 'codicon-list-flat', roles: ['owner', 'pbx_admin', 'pbx_user', 'reporter'] },
      { name: 'Call Notifications', href: '/ui/call-notifications', icon: 'codicon-bell', roles: ['owner', 'pbx_admin'] },
      { name: 'API Keys', href: '/ui/api-keys', icon: 'codicon-key', roles: ['owner'] },
    ],
  },
];

// Supervisor section — supervisors get a single top-level section with a
// fixed, use-case-driven item order. When a supervisor is signed in this
// REPLACES all other sections (they see nothing else).
const supervisorSection: SidebarSection = {
  id: 'supervisor',
  title: 'Supervisor',
  icon: 'codicon-shield',
  accentColor: 'default',
  items: [
    { name: 'Dashboard', href: '/ui/dashboard', icon: 'codicon-dashboard' },
    { name: 'Live Calls', href: '/ui/live-calls', icon: 'codicon-debug-rerun' },
    { name: 'Call Logs', href: '/ui/call-logs', icon: 'codicon-list-flat' },
    { name: 'Users', href: '/ui/users', icon: 'codicon-account' },
    { name: 'Ring Groups', href: '/ui/ring-groups', icon: 'codicon-call-incoming' },
  ],
};

// Platform Management section (shown only to platform managers)
const platformSection: SidebarSection = {
  id: 'platform',
  title: 'Platform Management',
  icon: 'codicon-server-environment',
  accentColor: 'amber',
  items: [
    { name: 'Dashboard', href: '/ui/platform/dashboard', icon: 'codicon-dashboard' },
    { name: 'Organizations', href: '/ui/platform/organizations', icon: 'codicon-organization' },
    { name: 'Users', href: '/ui/platform/users', icon: 'codicon-account' },
    { name: 'Audit Log', href: '/ui/platform/audit-log', icon: 'codicon-output' },
  ],
};

export function Sidebar() {
  const { user } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  // Selected section state
  const [selectedSectionId, setSelectedSectionId] = useState<string>('dashboards');

  // Width state for the sidebar (not including activity bar)
  const [sidebarWidth, setSidebarWidth] = useState(DEFAULT_SIDEBAR_WIDTH);
  const [isResizing, setIsResizing] = useState(false);



  const resizeRef = useRef<HTMLDivElement>(null);
  const startXRef = useRef(0);
  const startWidthRef = useRef(DEFAULT_SIDEBAR_WIDTH);

  // Check if user is a platform manager. While impersonating an organization,
  // hide the platform-management nav — the session is org-scoped and platform
  // endpoints are not part of the impersonated experience.
  const isPlatformManager = user?.is_platform_manager === true && !storage.isImpersonating();

  // Supervisors get ONLY the single Supervisor section; everyone else gets the
  // full section set. This is the source of truth for the whole sidebar.
  const baseSections = useMemo(
    () => (user?.role === 'supervisor' ? [supervisorSection] : sidebarSections),
    [user?.role]
  );

  // Load saved state from localStorage and sync with current URL
  useEffect(() => {
    const savedSection = localStorage.getItem(SELECTED_SECTION_KEY);
    const allSectionsList = [...baseSections, ...(isPlatformManager ? [platformSection] : [])];
    
    // First, check if current URL matches any section's items
    const currentPath = location.pathname;
    let matchingSection = allSectionsList.find(section => 
      section.items.some(item => !item.isHeader && currentPath.startsWith(item.href))
    );
    
    if (matchingSection) {
      // URL matches a section, use that
      setSelectedSectionId(matchingSection.id);
    } else if (savedSection) {
      // Fall back to saved section if it exists
      const sectionExists = allSectionsList.some(s => s.id === savedSection);
      if (sectionExists) {
        setSelectedSectionId(savedSection);
      }
    }

    const savedWidth = localStorage.getItem(SIDEBAR_WIDTH_KEY);
    if (savedWidth) {
      const width = parseInt(savedWidth, 10);
      if (width >= MIN_SIDEBAR_WIDTH && width <= MAX_SIDEBAR_WIDTH) {
        setSidebarWidth(width);
      }
    }
  }, [isPlatformManager, location.pathname, baseSections]);

  // Save selected section to localStorage
  useEffect(() => {
    localStorage.setItem(SELECTED_SECTION_KEY, selectedSectionId);
  }, [selectedSectionId]);

  // Save width to localStorage
  useEffect(() => {
    localStorage.setItem(SIDEBAR_WIDTH_KEY, sidebarWidth.toString());
  }, [sidebarWidth]);

  // Handle resize
  const handleResizeStart = useCallback((e: React.MouseEvent) => {
    e.preventDefault();
    setIsResizing(true);
    startXRef.current = e.clientX;
    startWidthRef.current = sidebarWidth;
  }, [sidebarWidth]);

  useEffect(() => {
    const handleMouseMove = (e: MouseEvent) => {
      if (!isResizing) return;
      const delta = e.clientX - startXRef.current - ACTIVITY_BAR_WIDTH;
      const newWidth = Math.max(MIN_SIDEBAR_WIDTH, Math.min(MAX_SIDEBAR_WIDTH, startWidthRef.current + delta));
      setSidebarWidth(newWidth);
    };

    const handleMouseUp = () => {
      setIsResizing(false);
    };

    if (isResizing) {
      document.addEventListener('mousemove', handleMouseMove);
      document.addEventListener('mouseup', handleMouseUp);
      document.body.style.cursor = 'col-resize';
      document.body.style.userSelect = 'none';
    }

    return () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
    };
  }, [isResizing]);

  // Filter items based on user role
  const getVisibleItems = (items: NavItem[]) => {
    return items.filter((item) => {
      if (!item.roles) return true;
      return user?.role && item.roles.includes(user.role);
    });
  };

  // Check if item is active
  const isItemActive = (href: string): boolean => {
    return location.pathname === href || location.pathname.startsWith(href + '/');
  };



  // Get the currently selected section
  const allSections = [...baseSections, ...(isPlatformManager ? [platformSection] : [])];
  const selectedSection = allSections.find(s => s.id === selectedSectionId) || baseSections[0];

  // Render a navigation item
  const renderNavItem = (item: NavItem) => {
    // Render non-clickable header
    if (item.isHeader) {
      return (
        <div
          key={item.name}
          className="px-3 py-2 mt-2 text-[11px] font-semibold uppercase tracking-wide text-[#858585]"
        >
          <span className="truncate">{item.name}</span>
        </div>
      );
    }

    const isActive = isItemActive(item.href);

    return (
      <NavLink
        key={item.name}
        to={item.href}
        className={cn(
          'flex items-center gap-3 py-2 pr-3 text-[13px] transition-colors relative group',
          isActive
            ? 'text-white bg-[#37373d]'
            : 'text-[#cccccc] hover:text-white hover:bg-[#2a2d2e]'
        )}
        style={{
          paddingLeft: '12px',
          borderLeft: isActive ? '2px solid #007acc' : '2px solid transparent',
        }}
      >
        <i className={cn('codicon', item.icon)} style={{ fontSize: '24px' }} />
        <span className="truncate">{item.name}</span>
      </NavLink>
    );
  };

  // Calculate total width for logo header
  const totalWidth = ACTIVITY_BAR_WIDTH + sidebarWidth;

  return (
    <div className="flex flex-col h-full">
      {/* Full-width Logo Header */}
      <div
        className="flex items-center justify-center bg-[#1e1e1e] border-b border-[#3c3c3c]"
        style={{ height: LOGO_HEADER_HEIGHT, width: totalWidth }}
      >
        <img
          src={opbxLogo}
          alt="OPBX"
          className="h-20 w-auto opacity-95"
          style={{ maxWidth: totalWidth - 32 }}
        />
      </div>

      {/* Navigation Panels */}
      <div className="flex flex-1">
        {/* Activity Bar */}
        <div
          className="flex flex-col bg-[#333333] border-r border-[#252526]"
          style={{ width: ACTIVITY_BAR_WIDTH }}
        >
          {/* Activity Icons */}
          <div className="flex-1 py-3 space-y-2">
            {baseSections.map(section => {
              const isSelected = selectedSectionId === section.id;

              if (section.roles && (!user?.role || !section.roles.includes(user.role))) {
                return null;
              }

              const visibleNavigableItems = getVisibleItems(section.items).filter(item => !item.isHeader);

              if (visibleNavigableItems.length === 0) return null;

              return (
                <button
                  key={section.id}
                  onClick={() => {
                    setSelectedSectionId(section.id);
                    if (section.id === 'dashboards') {
                      navigate('/ui/dashboard');
                    }
                  }}
                  className={cn(
                    'w-full flex items-center justify-center py-3 transition-all duration-150 relative group',
                    isSelected
                      ? 'text-white'
                      : 'text-[#858585] hover:text-white'
                  )}
                  title={section.title}
                >
                  {/* Active indicator - left border */}
                  {isSelected && (
                    <div className="absolute left-0 top-2 bottom-2 w-[3px] bg-white rounded-r-full" />
                  )}
                  
                  {/* Icon with larger size and bolder appearance */}
                  <i
                    className={cn('codicon', section.icon)}
                    style={{
                      fontSize: '24px',
                      filter: isSelected ? 'drop-shadow(0 0 4px rgba(255,255,255,0.6))' : 'none',
                    }}
                  />
                </button>
              );
            })}
          </div>

          {/* Platform Management - Bottom */}
          {isPlatformManager && (
            <div className="border-t border-[#252526] py-3">
              <button
                onClick={() => setSelectedSectionId('platform')}
                className={cn(
                  'w-full flex items-center justify-center py-3 transition-all duration-150 relative group',
                  selectedSectionId === 'platform'
                    ? 'text-amber-400'
                    : 'text-amber-600/60 hover:text-amber-400'
                )}
                title="Platform Management"
              >
                {selectedSectionId === 'platform' && (
                  <div className="absolute left-0 top-2 bottom-2 w-[3px] bg-amber-400 rounded-r-full" />
                )}
                <i
                  className="codicon codicon-server-environment"
                  style={{
                    fontSize: '24px',
                    filter: selectedSectionId === 'platform'
                      ? 'drop-shadow(0 0 6px rgba(251,191,36,0.6))'
                      : 'none',
                  }}
                />
              </button>
            </div>
          )}
        </div>

        {/* Contextual Sidebar */}
        <div
          className={cn(
            'flex flex-col bg-[#252526] text-white relative',
            isResizing && 'select-none'
          )}
          style={{ width: sidebarWidth }}
        >
          {/* Section Title Header */}
          <div className={cn(
            "flex items-center gap-2 px-3 py-3 border-b border-[#3c3c3c]",
            selectedSection.id === 'platform' ? "text-amber-400" : "text-[#bbbbbb]"
          )}>
            <i className={cn('codicon', selectedSection.icon)} style={{ fontSize: '24px' }} />
            <span className="font-semibold text-[13px] uppercase tracking-wide truncate">
              {selectedSection.title}
            </span>
          </div>

          {/* Navigation Items */}
          <nav className="flex-1 overflow-y-auto py-2">
            {getVisibleItems(selectedSection.items).map(item => renderNavItem(item))}
            
            {getVisibleItems(selectedSection.items).length === 0 && (
              <div className="px-3 py-4 text-[12px] text-[#858585] text-center">
                No items available
              </div>
            )}
          </nav>

          {/* Footer */}
          <div className="border-t border-[#3c3c3c] p-3">
            <div className="flex items-center gap-2">
              <div className={cn(
                "h-6 w-6 rounded-full flex items-center justify-center text-[11px] font-semibold",
                selectedSection.id === 'platform' ? "bg-amber-600 text-white" : "bg-[#007acc] text-white"
              )}>
                {user?.name?.charAt(0).toUpperCase() || 'U'}
              </div>
              <div className="flex-1 min-w-0">
                <p className="text-[12px] font-medium text-[#cccccc] truncate">{user?.name}</p>
                <p className="text-[10px] text-[#858585] truncate">{user?.email}</p>
              </div>
            </div>
          </div>

          {/* Resize Handle */}
          <div
            ref={resizeRef}
            onMouseDown={handleResizeStart}
            className={cn(
              'absolute right-0 top-0 bottom-0 w-1 cursor-col-resize transition-colors',
              isResizing ? 'bg-[#007acc]' : 'hover:bg-[#007acc]/50'
            )}
          />
        </div>
      </div>
    </div>
  );
}

// Export function to clear sidebar state (call on login)
export function clearSidebarState(): void {
  localStorage.removeItem(SELECTED_SECTION_KEY);
  localStorage.removeItem(SIDEBAR_WIDTH_KEY);
}

export default Sidebar;
