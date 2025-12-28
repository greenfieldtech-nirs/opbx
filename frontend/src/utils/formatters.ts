import { format, formatDistance, formatDistanceToNow } from 'date-fns';

/**
 * Format Utilities
 */

// Date formatting
export function formatDate(date: string | Date, pattern = 'MMM dd, yyyy'): string {
  if (!date) return 'N/A';
  const dateObj = new Date(date);
  if (isNaN(dateObj.getTime())) return 'Invalid date';
  return format(dateObj, pattern);
}

export function formatDateTime(date: string | Date): string {
  if (!date) return 'N/A';
  const dateObj = new Date(date);
  if (isNaN(dateObj.getTime())) return 'Invalid date';
  return format(dateObj, 'MMM dd, yyyy HH:mm:ss');
}

export function formatTimeAgo(date: string | Date): string {
  if (!date) return 'N/A';
  const dateObj = new Date(date);
  if (isNaN(dateObj.getTime())) return 'Invalid date';
  return formatDistanceToNow(dateObj, { addSuffix: true });
}

export function formatDuration(seconds: number): string {
  if (seconds < 60) {
    return `${seconds}s`;
  }

  const minutes = Math.floor(seconds / 60);
  const remainingSeconds = seconds % 60;

  if (minutes < 60) {
    return `${minutes}m ${remainingSeconds}s`;
  }

  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;

  return `${hours}h ${remainingMinutes}m`;
}

// Phone number formatting
// Formats E.164 phone numbers (+12125551234) to readable format
export function formatPhoneNumber(phoneNumber: string): string {
  if (!phoneNumber) return '';

  // Remove all non-numeric characters except leading +
  const cleaned = phoneNumber.replace(/[^\d+]/g, '');

  // If it doesn't start with +, return as-is
  if (!cleaned.startsWith('+')) {
    return phoneNumber;
  }

  // Remove the + for easier parsing
  const digits = cleaned.substring(1);

  // US/Canada numbers (+1 XXX XXX XXXX)
  if (digits.length === 11 && digits.startsWith('1')) {
    const countryCode = digits.substring(0, 1);
    const areaCode = digits.substring(1, 4);
    const exchange = digits.substring(4, 7);
    const subscriber = digits.substring(7, 11);
    return `+${countryCode} (${areaCode}) ${exchange}-${subscriber}`;
  }

  // UK numbers (+44 XXXX XXXXXX)
  if (digits.length >= 10 && digits.startsWith('44')) {
    const countryCode = digits.substring(0, 2);
    const areaCode = digits.substring(2, 6);
    const local = digits.substring(6);
    return `+${countryCode} ${areaCode} ${local}`;
  }

  // Israel numbers (+972 XX XXX XXXX)
  if (digits.length >= 10 && digits.startsWith('972')) {
    const countryCode = digits.substring(0, 3);
    const prefix = digits.substring(3, 5);
    const part1 = digits.substring(5, 8);
    const part2 = digits.substring(8);
    return `+${countryCode} ${prefix} ${part1} ${part2}`;
  }

  // Generic international format: +XX XXXX XXXX
  if (digits.length > 10) {
    const countryCode = digits.substring(0, digits.length - 10);
    const remaining = digits.substring(digits.length - 10);
    const part1 = remaining.substring(0, 4);
    const part2 = remaining.substring(4, 8);
    const part3 = remaining.substring(8);
    return `+${countryCode} ${part1} ${part2}${part3 ? ' ' + part3 : ''}`;
  }

  // Shorter international numbers: just add spaces every 3-4 digits
  if (digits.length > 6) {
    const countryCode = digits.substring(0, Math.min(3, digits.length - 6));
    const remaining = digits.substring(countryCode.length);
    const parts = [];
    for (let i = 0; i < remaining.length; i += 3) {
      parts.push(remaining.substring(i, i + 3));
    }
    return `+${countryCode} ${parts.join(' ')}`;
  }

  // Return with + if nothing else matches
  return cleaned;
}

// Status badge colors
export function getStatusColor(status: string): string {
  const statusColors: Record<string, string> = {
    active: 'bg-green-100 text-green-800',
    inactive: 'bg-gray-100 text-gray-800',
    suspended: 'bg-red-100 text-red-800',
    ringing: 'bg-blue-100 text-blue-800',
    answered: 'bg-green-100 text-green-800',
    completed: 'bg-gray-100 text-gray-800',
    busy: 'bg-yellow-100 text-yellow-800',
    no_answer: 'bg-orange-100 text-orange-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-800',
  };

  return statusColors[status] || 'bg-gray-100 text-gray-800';
}

// Disposition badge colors for CDRs
export function getDispositionColor(disposition: string): string {
  const dispositionColors: Record<string, string> = {
    ANSWERED: 'bg-green-100 text-green-800 border border-green-200',
    ANSWER: 'bg-green-100 text-green-800 border border-green-200',
    BUSY: 'bg-cyan-100 text-cyan-800 border border-cyan-200',
    CANCEL: 'bg-yellow-100 text-yellow-800 border border-yellow-200',
    CANCELLED: 'bg-yellow-100 text-yellow-800 border border-yellow-200',
    CONGESTION: 'bg-red-100 text-red-800 border border-red-200',
    FAILED: 'bg-orange-100 text-orange-800 border border-orange-200',
    'NO ANSWER': 'bg-blue-100 text-blue-800 border border-blue-200',
    NOANSWER: 'bg-blue-100 text-blue-800 border border-blue-200',
  };

  const normalizedDisposition = disposition.toUpperCase();
  return dispositionColors[normalizedDisposition] || 'bg-gray-100 text-gray-800 border border-gray-200';
}

// Role badge colors
export function getRoleColor(role: string): string {
  const roleColors: Record<string, string> = {
    owner: 'bg-purple-100 text-purple-800 border border-purple-200',
    pbx_admin: 'bg-blue-100 text-blue-800 border border-blue-200',
    pbx_user: 'bg-gray-100 text-gray-800 border border-gray-200',
    reporter: 'bg-green-100 text-green-800 border border-green-200',
    // Legacy support
    admin: 'bg-blue-100 text-blue-800 border border-blue-200',
    agent: 'bg-green-100 text-green-800 border border-green-200',
  };

  return roleColors[role] || 'bg-gray-100 text-gray-800 border border-gray-200';
}

// Role display name
export function getRoleDisplayName(role: string): string {
  const roleNames: Record<string, string> = {
    owner: 'Owner',
    pbx_admin: 'PBX Admin',
    pbx_user: 'PBX User',
    reporter: 'Reporter',
    // Legacy support
    admin: 'Admin',
    agent: 'Agent',
  };

  return roleNames[role] || role;
}

// Capitalize first letter
export function capitalize(str: string): string {
  return str.charAt(0).toUpperCase() + str.slice(1);
}

// Format file size
export function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';

  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));

  return `${Math.round(bytes / Math.pow(k, i) * 100) / 100} ${sizes[i]}`;
}

// Format currency
export function formatCurrency(amount: number, currency = 'USD'): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
  }).format(amount);
}

// Truncate text
export function truncate(str: string, length: number): string {
  if (str.length <= length) return str;
  return `${str.slice(0, length)}...`;
}
