/**
 * Destination Selector Types
 *
 * Type definitions for the generic destination selector components.
 * These types provide a unified interface for selecting destinations
 * across all pages (IVR Menus, Ring Groups, DID Numbers, etc.)
 */

import type { LucideIcon } from 'lucide-react';

/**
 * Available destination types for routing/fallback selection
 */
export type DestinationType =
  | 'extension'
  | 'user'
  | 'forward'
  | 'ring_group'
  | 'conference_room'
  | 'ivr_menu'
  | 'business_hours'
  | 'ai_assistant'
  | 'ai_load_balancer'
  | 'hangup';

/**
 * Category for grouping destination types in UI
 */
export type DestinationCategory = 'pbx' | 'ai' | 'routing' | 'termination';

/**
 * Represents a single destination option in the selector
 */
export interface DestinationOption {
  /** Unique identifier for the destination */
  id: string;
  /** Type of destination */
  type: DestinationType;
  /** Primary display label */
  label: string;
  /** Secondary/sub label for additional context */
  subLabel?: string;
  /** Icon identifier */
  icon?: string;
  /** Badge to display (e.g., 'User', 'Forward', 'AI') */
  badge?: {
    color: string;
    text: string;
  };
  /** Additional metadata specific to the destination type */
  metadata?: Record<string, any>;
}

/**
 * Metadata for a destination type (used in type selector)
 */
export interface TypeMetadata {
  /** The destination type value */
  value: DestinationType;
  /** Human-readable label */
  label: string;
  /** Description/helper text */
  description: string;
  /** Lucide icon component */
  icon: LucideIcon;
  /** Category for grouping */
  category: DestinationCategory;
  /** Whether this type requires a destination selection */
  requiresDestination: boolean;
}

/**
 * Extension types for filtering extensions in destination selector
 */
export type ExtensionType = 'user' | 'forward' | 'ai_assistant';

/**
 * Props for DestinationTypeSelector component
 */
export interface DestinationTypeSelectorProps {
  /** Currently selected destination type */
  value: DestinationType | null;
  /** Callback when type changes */
  onChange: (value: DestinationType, metadata: TypeMetadata) => void;
  /** Label for the field */
  label?: string;
  /** Placeholder text */
  placeholder?: string;
  /** Whether the field is disabled */
  disabled?: boolean;
  /** Filter to only show specific types (shows all if not provided) */
  allowedTypes?: DestinationType[];
  /** Include 'hangup' option (typically for failover) */
  includeHangup?: boolean;
  /** Show descriptions under each option */
  showDescriptions?: boolean;
  /** Size variant */
  size?: 'sm' | 'default' | 'lg';
  /** Additional CSS classes */
  className?: string;
}

/**
 * Props for DestinationSelector component
 */
export interface DestinationSelectorProps {
  /** Selected destination type (determines what to show) */
  type: DestinationType | null;
  /** Currently selected destination ID */
  value?: string;
  /** Callback when destination changes */
  onChange: (value: string, option: DestinationOption) => void;
  /** Label for the field */
  label?: string;
  /** Placeholder text */
  placeholder?: string;
  /** Whether the field is disabled */
  disabled?: boolean;
  /** Organization ID for fetching destinations */
  organizationId?: string;
  /** Filter extensions by type (default: ['user', 'forward']) */
  extensionTypes?: ExtensionType[];
  /** Show type badges in dropdown */
  showBadges?: boolean;
  /** Custom empty state message */
  emptyMessage?: string;
  /** Custom loading message */
  loadingMessage?: string;
  /** Additional CSS classes */
  className?: string;
}

/**
 * Props for DestinationTypeAndSelector combined component
 */
export interface DestinationTypeAndSelectorProps {
  /** Currently selected destination type */
  typeValue: DestinationType | null;
  /** Currently selected destination ID */
  destinationValue?: string;
  /** Callback when either type or destination changes */
  onChange: (type: DestinationType, destinationId: string) => void;
  /** Label for type field */
  typeLabel?: string;
  /** Label for destination field */
  destinationLabel?: string;
  /** Whether fields are disabled */
  disabled?: boolean;
  /** Filter available types */
  allowedTypes?: DestinationType[];
  /** Include 'hangup' option */
  includeHangup?: boolean;
  /** Layout arrangement */
  layout?: 'horizontal' | 'vertical' | 'grid';
  /** Grid column widths (when layout='grid') */
  gridColumns?: {
    type: number;
    destination: number;
  };
  /** Filter extensions by type */
  extensionTypes?: ExtensionType[];
  /** Show type descriptions */
  showDescriptions?: boolean;
  /** Additional CSS classes for container */
  className?: string;
  /** Additional CSS classes for type selector container */
  typeClassName?: string;
  /** Additional CSS classes for destination selector container */
  destinationClassName?: string;
}

/**
 * Props for DestinationBadge component
 */
export interface DestinationBadgeProps {
  /** Destination type */
  type: DestinationType;
  /** Primary label */
  label: string;
  /** Extension subtype (for 'extension' type) */
  subType?: ExtensionType;
  /** Size variant */
  size?: 'sm' | 'md' | 'lg';
  /** Whether to show the icon */
  showIcon?: boolean;
  /** Additional CSS classes */
  className?: string;
}

/**
 * Data structure returned by useDestinations hook
 */
export interface DestinationsData {
  /** User and forward extensions */
  extensions: Array<{
    id: string;
    extension_number: string;
    type: string;
    label: string;
    user?: { name: string };
  }>;
  /** Ring groups */
  ringGroups: Array<{
    id: string;
    name: string;
    label: string;
  }>;
  /** Conference rooms */
  conferenceRooms: Array<{
    id: string;
    name: string;
    label: string;
  }>;
  /** IVR menus */
  ivrMenus: Array<{
    id: string;
    name: string;
    label: string;
  }>;
  /** Business hours schedules */
  businessHours: Array<{
    id: string;
    name: string;
    label: string;
  }>;
  /** AI assistant extensions */
  aiAssistants: Array<{
    id: string;
    extension_number: string;
    name: string;
    label: string;
  }>;
  /** AI load balancers */
  aiLoadBalancers: Array<{
    id: string;
    name: string;
    label: string;
  }>;
  /** Users */
  users: Array<{
    id: string;
    name: string;
    email: string;
    extension?: { extension_number: string };
  }>;
}

/**
 * Loading state for useDestinations hook
 */
export interface DestinationsLoadingState {
  extensions: boolean;
  ringGroups: boolean;
  conferenceRooms: boolean;
  ivrMenus: boolean;
  businessHours: boolean;
  aiAssistants: boolean;
  aiLoadBalancers: boolean;
  users: boolean;
}

/**
 * Error state for useDestinations hook
 */
export interface DestinationsErrorState {
  extensions: Error | null;
  ringGroups: Error | null;
  conferenceRooms: Error | null;
  ivrMenus: Error | null;
  businessHours: Error | null;
  aiAssistants: Error | null;
  aiLoadBalancers: Error | null;
  users: Error | null;
}

/**
 * Return type for useDestinations hook
 */
export interface UseDestinationsReturn {
  /** All destination data */
  data: DestinationsData;
  /** Loading states per destination type */
  isLoading: DestinationsLoadingState;
  /** Error states per destination type */
  isError: DestinationsErrorState;
  /** Overall loading state */
  isLoadingAny: boolean;
  /** Overall error state */
  isErrorAny: boolean;
  /** Refetch all destinations */
  refetch: () => void;
  /** Refetch specific destination type */
  refetchType: (type: DestinationType) => void;
}
