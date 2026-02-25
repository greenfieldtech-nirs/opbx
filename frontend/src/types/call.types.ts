/**
 * Call Status Enums and Utilities
 *
 * Shared constants for call states across frontend and backend.
 *
 * Status Lifecycle:
 * INITIATED → PROCESSING → RINGING → CONNECTED → ANSWERED (CDR)
 *                                    ↓
 *                              (other terminal states)
 */

/**
 * Call status values for real-time and historical call tracking
 */
export enum CallStatus {
  // Initial states (call being set up)
  INITIATED = 'initiated', // Call created, not yet processing
  PROCESSING = 'processing', // Being routed/processed by the system

  // Ringing state
  RINGING = 'ringing', // Destination is ringing

  // Active state (live calls only)
  CONNECTED = 'connected', // Call is active/ongoing (live conversation in progress)

  // Terminal states (CDR/historical)
  ANSWERED = 'answered', // Call was completed AND was answered (from CDR)
  COMPLETED = 'completed', // Normal hangup
  FAILED = 'failed', // Error/failure
  BUSY = 'busy', // Line busy
  NO_ANSWER = 'no_answer', // Timeout, no pickup
  CANCELLED = 'cancelled', // Caller hung up before answer
}

/**
 * Statuses that represent active/live calls
 * These appear in the Live Calls monitoring view
 */
export const LiveCallStatuses: CallStatus[] = [
  CallStatus.INITIATED,
  CallStatus.PROCESSING,
  CallStatus.RINGING,
  CallStatus.CONNECTED,
];

/**
 * Statuses that represent terminal/completed calls
 * These appear in Call Logs/CDR, NOT in Live Calls
 */
export const TerminalCallStatuses: CallStatus[] = [
  CallStatus.ANSWERED,
  CallStatus.COMPLETED,
  CallStatus.FAILED,
  CallStatus.BUSY,
  CallStatus.NO_ANSWER,
  CallStatus.CANCELLED,
];

/**
 * Display labels for each status
 */
export const CallStatusLabels: Record<CallStatus, string> = {
  [CallStatus.INITIATED]: 'Initiated',
  [CallStatus.PROCESSING]: 'Processing',
  [CallStatus.RINGING]: 'Ringing',
  [CallStatus.CONNECTED]: 'Connected',
  [CallStatus.ANSWERED]: 'Answered',
  [CallStatus.COMPLETED]: 'Completed',
  [CallStatus.FAILED]: 'Failed',
  [CallStatus.BUSY]: 'Busy',
  [CallStatus.NO_ANSWER]: 'No Answer',
  [CallStatus.CANCELLED]: 'Cancelled',
};

/**
 * Tailwind CSS color classes for status badges
 */
export const CallStatusColors: Record<CallStatus, string> = {
  [CallStatus.INITIATED]: 'bg-gray-100 text-gray-800 border-gray-200',
  [CallStatus.PROCESSING]: 'bg-blue-100 text-blue-800 border-blue-200',
  [CallStatus.RINGING]: 'bg-yellow-100 text-yellow-800 border-yellow-200',
  [CallStatus.CONNECTED]: 'bg-green-100 text-green-800 border-green-200',
  [CallStatus.ANSWERED]: 'bg-green-100 text-green-800 border-green-200',
  [CallStatus.COMPLETED]: 'bg-gray-100 text-gray-800 border-gray-200',
  [CallStatus.FAILED]: 'bg-red-100 text-red-800 border-red-200',
  [CallStatus.BUSY]: 'bg-orange-100 text-orange-800 border-orange-200',
  [CallStatus.NO_ANSWER]: 'bg-gray-100 text-gray-800 border-gray-200',
  [CallStatus.CANCELLED]: 'bg-gray-100 text-gray-800 border-gray-200',
};

/**
 * Check if a status represents a live/active call
 */
export function isLiveCallStatus(status: CallStatus | string): boolean {
  return LiveCallStatuses.includes(status as CallStatus);
}

/**
 * Check if a status represents a completed/terminal call
 */
export function isTerminalCallStatus(status: CallStatus | string): boolean {
  return TerminalCallStatuses.includes(status as CallStatus);
}

/**
 * Get display label for a status
 */
export function getCallStatusLabel(status: CallStatus | string): string {
  return CallStatusLabels[status as CallStatus] || String(status);
}

/**
 * Get color classes for a status
 */
export function getCallStatusColor(status: CallStatus | string): string {
  return CallStatusColors[status as CallStatus] || 'bg-gray-100 text-gray-800 border-gray-200';
}
