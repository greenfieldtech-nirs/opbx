// Design System Tokens
// Use these constants to maintain visual consistency across the application

// ============================================================================
// COLORS
// ============================================================================

export const colors = {
  // Primary brand colors
  primary: {
    50: '#eff6ff',
    100: '#dbeafe',
    200: '#bfdbfe',
    300: '#93c5fd',
    400: '#60a5fa',
    500: '#3b82f6',
    600: '#2563eb',
    700: '#1d4ed8',
    800: '#1e40af',
    900: '#1e3a8a',
  },
  
  // Success/green colors
  success: {
    50: '#f0fdf4',
    100: '#dcfce7',
    200: '#bbf7d0',
    300: '#86efac',
    400: '#4ade80',
    500: '#22c55e',
    600: '#16a34a',
    700: '#15803d',
    800: '#166534',
    900: '#14532d',
  },
  
  // Warning/yellow colors
  warning: {
    50: '#fefce8',
    100: '#fef9c3',
    200: '#fef08a',
    300: '#fde047',
    400: '#facc15',
    500: '#eab308',
    600: '#ca8a04',
    700: '#a16207',
    800: '#854d0e',
    900: '#713f12',
  },
  
  // Error/red colors
  error: {
    50: '#fef2f2',
    100: '#fee2e2',
    200: '#fecaca',
    300: '#fca5a5',
    400: '#f87171',
    500: '#ef4444',
    600: '#dc2626',
    700: '#b91c1c',
    800: '#991b1b',
    900: '#7f1d1d',
  },
  
  // Neutral/gray colors
  gray: {
    50: '#f9fafb',
    100: '#f3f4f6',
    200: '#e5e7eb',
    300: '#d1d5db',
    400: '#9ca3af',
    500: '#6b7280',
    600: '#4b5563',
    700: '#374151',
    800: '#1f2937',
    900: '#111827',
  },
  
  // Muted/foreground colors
  muted: {
    foreground: '#6b7280',
    background: '#f9fafb',
    border: '#e5e7eb',
  },
} as const;

// ============================================================================
// SPACING
// ============================================================================

export const spacing = {
  0: '0',
  1: '0.25rem',    // 4px
  2: '0.5rem',     // 8px
  3: '0.75rem',    // 12px
  4: '1rem',       // 16px
  5: '1.25rem',    // 20px
  6: '1.5rem',     // 24px
  8: '2rem',       // 32px
  10: '2.5rem',    // 40px
  12: '3rem',      // 48px
  16: '4rem',      // 64px
  20: '5rem',      // 80px
} as const;

// ============================================================================
// BORDER RADIUS
// ============================================================================

export const borderRadius = {
  none: '0',
  sm: '0.25rem',       // 4px
  md: '0.375rem',      // 6px
  DEFAULT: '0.375rem',
  lg: '0.5rem',        // 8px
  xl: '0.75rem',       // 12px
  '2xl': '1rem',       // 16px
  full: '9999px',      // Circular
} as const;

// ============================================================================
// TYPOGRAPHY
// ============================================================================

export const typography = {
  // Font families
  fontFamily: {
    sans: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
    mono: 'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace',
  },
  
  // Font sizes
  fontSize: {
    xs: '0.75rem',     // 12px
    sm: '0.875rem',    // 14px
    base: '1rem',      // 16px
    lg: '1.125rem',    // 18px
    xl: '1.25rem',     // 20px
    '2xl': '1.5rem',   // 24px
    '3xl': '1.875rem', // 30px
    '4xl': '2.25rem',  // 36px
    '5xl': '3rem',     // 48px
    '6xl': '3.75rem',  // 60px
  },
  
  // Font weights
  fontWeight: {
    normal: '400',
    medium: '500',
    semibold: '600',
    bold: '700',
  },
  
  // Line heights
  lineHeight: {
    tight: '1.25',
    normal: '1.5',
    relaxed: '1.75',
  },
} as const;

// ============================================================================
// SHADOWS
// ============================================================================

export const shadows = {
  none: 'none',
  sm: '0 1px 2px 0 rgba(0, 0, 0, 0.05)',
  DEFAULT: '0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06)',
  md: '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
  lg: '0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05)',
  xl: '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04)',
  '2xl': '0 25px 50px -12px rgba(0, 0, 0, 0.25)',
} as const;

// ============================================================================
// TRANSITIONS
// ============================================================================

export const transitions = {
  duration: {
    75: '75ms',
    100: '100ms',
    150: '150ms',
    200: '200ms',
    300: '300ms',
    500: '500ms',
    700: '700ms',
    1000: '1000ms',
  },
  timing: {
    DEFAULT: 'cubic-bezier(0.4, 0, 0.2, 1)',
    linear: 'linear',
    easeIn: 'cubic-bezier(0.4, 0, 1, 1)',
    easeOut: 'cubic-bezier(0, 0, 0.2, 1)',
    easeInOut: 'cubic-bezier(0.4, 0, 0.2, 1)',
  },
} as const;

// ============================================================================
// Z-INDEX
// ============================================================================

export const zIndex = {
  auto: 'auto',
  0: '0',
  10: '10',
  20: '20',
  30: '30',
  40: '40',
  50: '50',
  dropdown: '1000',
  sticky: '1100',
  fixed: '1200',
  modal: '1300',
  popover: '1400',
  toast: '1500',
  tooltip: '1600',
} as const;

// ============================================================================
// BREAKPOINTS
// ============================================================================

export const breakpoints = {
  sm: '640px',
  md: '768px',
  lg: '1024px',
  xl: '1280px',
  '2xl': '1536px',
} as const;

// ============================================================================
// COMPONENT STYLES
// ============================================================================

// Button styles following the design system
export const buttonStyles = {
  primary: `
    bg-blue-600
    text-white
    hover:bg-blue-700
    focus:ring-2
    focus:ring-blue-500
    focus:ring-offset-2
    disabled:opacity-50
    disabled:cursor-not-allowed
  `,
  secondary: `
    bg-gray-100
    text-gray-700
    hover:bg-gray-200
    focus:ring-2
    focus:ring-gray-500
    focus:ring-offset-2
    disabled:opacity-50
    disabled:cursor-not-allowed
  `,
  danger: `
    bg-red-600
    text-white
    hover:bg-red-700
    focus:ring-2
    focus:ring-red-500
    focus:ring-offset-2
    disabled:opacity-50
    disabled:cursor-not-allowed
  `,
  outline: `
    border
    border-gray-300
    text-gray-700
    hover:bg-gray-50
    focus:ring-2
    focus:ring-gray-500
    focus:ring-offset-2
    disabled:opacity-50
    disabled:cursor-not-allowed
  `,
  ghost: `
    text-gray-700
    hover:bg-gray-100
    hover:text-gray-900
    focus:ring-2
    focus:ring-gray-500
    focus:ring-offset-2
    disabled:opacity-50
    disabled:cursor-not-allowed
  `,
  link: `
    text-blue-600
    hover:text-blue-700
    hover:underline
    focus:ring-2
    focus:ring-blue-500
    focus:ring-offset-2
    disabled:opacity-50
    disabled:cursor-not-allowed
    disabled:no-underline
  `,
} as const;

// Card styles
export const cardStyles = {
  default: `
    bg-white
    rounded-lg
    shadow
    overflow-hidden
  `,
  bordered: `
    bg-white
    rounded-lg
    border
    border-gray-200
    overflow-hidden
  `,
  elevated: `
    bg-white
    rounded-lg
    shadow-lg
    overflow-hidden
  `,
} as const;

// Input styles
export const inputStyles = {
  default: `
    w-full
    px-3
    py-2
    border
    border-gray-300
    rounded-md
    shadow-sm
    placeholder-gray-400
    focus:outline-none
    focus:ring-2
    focus:ring-blue-500
    focus:border-blue-500
    disabled:bg-gray-100
    disabled:cursor-not-allowed
  `,
  error: `
    w-full
    px-3
    py-2
    border
    border-red-500
    rounded-md
    shadow-sm
    placeholder-gray-400
    focus:outline-none
    focus:ring-2
    focus:ring-red-500
    focus:border-red-500
    disabled:bg-gray-100
    disabled:cursor-not-allowed
  `,
} as const;

// Status badge styles
export const statusBadgeStyles = {
  active: 'bg-green-100 text-green-800',
  inactive: 'bg-gray-100 text-gray-800',
  pending: 'bg-yellow-100 text-yellow-800',
  error: 'bg-red-100 text-red-800',
  success: 'bg-green-100 text-green-800',
  warning: 'bg-yellow-100 text-yellow-800',
  info: 'bg-blue-100 text-blue-800',
} as const;

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

type ColorShade = 50 | 100 | 200 | 300 | 400 | 500 | 600 | 700 | 800 | 900;

/**
 * Get color value for a given color and shade
 */
export function getColor(colorName: string, shade: ColorShade): string {
  const colorGroup = colors[colorName as keyof typeof colors];
  if (!colorGroup) return '';
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  return (colorGroup as any)[shade] || '';
}

/**
 * Get spacing value
 */
export function getSpacing(size: keyof typeof spacing): string {
  return spacing[size] || '';
}

/**
 * Get border radius value
 */
export function getBorderRadius(size: keyof typeof borderRadius): string {
  return borderRadius[size] || borderRadius.DEFAULT;
}

/**
 * Get font size value
 */
export function getFontSize(size: keyof typeof typography.fontSize): string {
  return typography.fontSize[size] || typography.fontSize.base;
}

/**
 * Get font weight value
 */
export function getFontWeight(weight: keyof typeof typography.fontWeight): string {
  return typography.fontWeight[weight] || typography.fontWeight.normal;
}

/**
 * Get shadow value
 */
export function getShadow(size: keyof typeof shadows): string {
  return shadows[size] || shadows.DEFAULT;
}

// ============================================================================
// DEFAULT EXPORTS
// ============================================================================

export default {
  colors,
  spacing,
  borderRadius,
  typography,
  shadows,
  transitions,
  zIndex,
  breakpoints,
  buttonStyles,
  cardStyles,
  inputStyles,
  statusBadgeStyles,
  getColor,
  getSpacing,
  getBorderRadius,
  getFontSize,
  getFontWeight,
  getShadow,
};
