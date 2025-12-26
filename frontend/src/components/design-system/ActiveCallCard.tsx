/**
 * ActiveCallCard Component
 *
 * Displays a live active call with real-time updates
 * Used in Live Calls page
 *
 * Features:
 * - Pulsing ring animation for ringing calls
 * - Real-time duration counter
 * - Status badge
 * - Caller/Callee information
 * - Extension/Ring Group routing info
 *
 * @example
 * <ActiveCallCard
 *   call={{
 *     call_id: '123',
 *     from_number: '+12345678901',
 *     to_number: '+19876543210',
 *     status: 'ringing',
 *     duration: 45,
 *     extension_number: '101',
 *   }}
 * />
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CallStatusBadge, CallStatus } from './CallStatusBadge';
import { PhoneCall, Hash, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import { formatPhoneNumber, formatDuration } from '@/utils/formatters';

export interface ActiveCallData {
  call_id: string;
  from_number: string;
  to_number: string;
  did_number?: string;
  status: CallStatus;
  duration: number;
  extension_number?: string;
  ring_group_name?: string;
  started_at: string;
}

interface ActiveCallCardProps {
  call: ActiveCallData;
  className?: string;
}

export function ActiveCallCard({ call, className }: ActiveCallCardProps) {
  const isRinging = call.status === 'ringing';
  const isAnswered = call.status === 'answered';

  return (
    <Card
      className={cn(
        'transition-all',
        isRinging && 'ring-2 ring-warning-300 animate-pulse',
        isAnswered && 'ring-1 ring-success-300',
        className
      )}
    >
      <CardHeader>
        <div className="flex items-center justify-between">
          {/* Caller Info */}
          <div className="flex items-center gap-3">
            <div
              className={cn(
                'h-12 w-12 rounded-full flex items-center justify-center',
                isRinging && 'bg-warning-100',
                isAnswered && 'bg-success-100',
                !isRinging && !isAnswered && 'bg-neutral-100'
              )}
            >
              <PhoneCall
                className={cn(
                  'h-6 w-6',
                  isRinging && 'text-warning-600 animate-pulse',
                  isAnswered && 'text-success-600',
                  !isRinging && !isAnswered && 'text-neutral-600'
                )}
              />
            </div>
            <div>
              <CardTitle className="text-lg font-semibold">
                {formatPhoneNumber(call.from_number)}
              </CardTitle>
              <p className="text-sm text-neutral-500">
                To: {formatPhoneNumber(call.to_number)}
              </p>
            </div>
          </div>

          {/* Status Badge */}
          <CallStatusBadge status={call.status} size="lg" />
        </div>
      </CardHeader>

      <CardContent>
        <div className="grid grid-cols-2 gap-4 text-sm">
          {/* DID Number */}
          {call.did_number && (
            <div>
              <p className="text-neutral-500 flex items-center gap-1 mb-1">
                <PhoneCall className="h-3.5 w-3.5" />
                DID
              </p>
              <p className="font-medium text-neutral-900">
                {formatPhoneNumber(call.did_number)}
              </p>
            </div>
          )}

          {/* Extension or Ring Group */}
          {call.extension_number && (
            <div>
              <p className="text-neutral-500 flex items-center gap-1 mb-1">
                <Hash className="h-3.5 w-3.5" />
                Extension
              </p>
              <p className="font-medium text-neutral-900">
                {call.extension_number}
              </p>
            </div>
          )}

          {call.ring_group_name && (
            <div>
              <p className="text-neutral-500 flex items-center gap-1 mb-1">
                <Users className="h-3.5 w-3.5" />
                Ring Group
              </p>
              <p className="font-medium text-neutral-900">
                {call.ring_group_name}
              </p>
            </div>
          )}

          {/* Duration - Real-time updating */}
          <div>
            <p className="text-neutral-500 mb-1">Duration</p>
            <p
              className={cn(
                'font-mono text-lg font-bold',
                isAnswered ? 'text-success-600' : 'text-neutral-900'
              )}
            >
              {formatDuration(call.duration)}
            </p>
          </div>

          {/* Start Time */}
          <div>
            <p className="text-neutral-500 mb-1">Started</p>
            <p className="font-medium text-neutral-900">
              {new Date(call.started_at).toLocaleTimeString()}
            </p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
