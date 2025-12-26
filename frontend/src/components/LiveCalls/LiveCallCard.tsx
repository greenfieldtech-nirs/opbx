/**
 * Live Call Card Component
 *
 * Display individual live call with real-time duration
 */

import { useEffect, useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { PhoneCall, Clock, Phone, User } from 'lucide-react';
import { formatPhoneNumber, formatDuration } from '@/utils/formatters';
import { cn } from '@/lib/utils';
import type { LiveCall } from '@/types/api.types';

interface LiveCallCardProps {
  call: LiveCall;
}

export function LiveCallCard({ call }: LiveCallCardProps) {
  const [duration, setDuration] = useState(call.duration || 0);

  // Update duration every second
  useEffect(() => {
    const interval = setInterval(() => {
      const startTime = new Date(call.started_at).getTime();
      const now = Date.now();
      const seconds = Math.floor((now - startTime) / 1000);
      setDuration(seconds);
    }, 1000);

    return () => clearInterval(interval);
  }, [call.started_at]);

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'initiated':
        return 'bg-yellow-100 text-yellow-800';
      case 'ringing':
        return 'bg-blue-100 text-blue-800';
      case 'answered':
        return 'bg-green-100 text-green-800';
      default:
        return 'bg-gray-100 text-gray-800';
    }
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'initiated':
      case 'ringing':
        return 'animate-pulse';
      case 'answered':
        return '';
      default:
        return '';
    }
  };

  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-4">
          {/* Call Info */}
          <div className="flex-1 space-y-3">
            {/* Status Badge */}
            <div className="flex items-center gap-2">
              <div className={cn('flex h-8 w-8 items-center justify-center rounded-full bg-blue-100', getStatusIcon(call.status))}>
                <PhoneCall className="h-4 w-4 text-blue-600" />
              </div>
              <Badge className={cn('capitalize', getStatusColor(call.status))}>
                {call.status}
              </Badge>
            </div>

            {/* Caller Information */}
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                  <Phone className="h-3 w-3" />
                  <span>From</span>
                </div>
                <p className="font-medium">{formatPhoneNumber(call.from_number)}</p>
              </div>

              <div className="space-y-1">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                  <Phone className="h-3 w-3" />
                  <span>To</span>
                </div>
                <p className="font-medium">
                  {call.did_number ? formatPhoneNumber(call.did_number) : formatPhoneNumber(call.to_number)}
                </p>
              </div>

              {call.extension_number && (
                <div className="space-y-1">
                  <div className="flex items-center gap-2 text-xs text-muted-foreground">
                    <User className="h-3 w-3" />
                    <span>Extension</span>
                  </div>
                  <p className="font-medium">{call.extension_number}</p>
                </div>
              )}

              <div className="space-y-1">
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                  <Clock className="h-3 w-3" />
                  <span>Duration</span>
                </div>
                <p className="font-medium font-mono">{formatDuration(duration)}</p>
              </div>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
