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
export function formatPhoneNumber(phoneNumber: string): string {
  // Remove all non-numeric characters
  const cleaned = phoneNumber.replace(/\D/g, '');

  // Format as: +1 (555) 123-4567 for US numbers
  if (cleaned.length === 11 && cleaned.startsWith('1')) {
    return `+1 (${cleaned.slice(1, 4)}) ${cleaned.slice(4, 7)}-${cleaned.slice(7)}`;
  }

  // Format international numbers
  if (cleaned.length > 10) {
    return `+${cleaned}`;
  }

  // Format as: (555) 123-4567 for 10-digit numbers
  if (cleaned.length === 10) {
    return `(${cleaned.slice(0, 3)}) ${cleaned.slice(3, 6)}-${cleaned.slice(6)}`;
  }

  // Return as-is if format is unknown
  return phoneNumber;
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
