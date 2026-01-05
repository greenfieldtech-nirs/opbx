/**
 * useCallPresence Hook
 *
 * React hook for managing real-time call presence updates
 * Automatically subscribes to organization presence channel and tracks active calls
 */

import { useEffect, useState, useCallback } from 'react';
import { echoService, type CallInitiatedData, type CallAnsweredData, type CallEndedData, type PresenceMember } from '@/services/echo.service';
import { useAuth } from './useAuth';
import logger from '@/utils/logger';

export interface ActiveCall {
  call_id: string;
  from_number: string;
  to_number: string;
  did_id: string | null;
  extension_id?: string;
  status: string;
  initiated_at: string;
  answered_at?: string;
  duration: number; // Duration in seconds (calculated on frontend)
}

export interface CallPresenceState {
  activeCalls: ActiveCall[];
  onlineMembers: PresenceMember[];
  totalActiveCalls: number;
  isConnected: boolean;
  connectionState: 'disconnected' | 'connecting' | 'connected';
}

/**
 * Hook to manage call presence and real-time updates
 */
export function useCallPresence(): CallPresenceState {
  const { user, token, isAuthenticated } = useAuth();
  const [activeCalls, setActiveCalls] = useState<ActiveCall[]>([]);
  const [onlineMembers, setOnlineMembers] = useState<PresenceMember[]>([]);
  const [connectionState, setConnectionState] = useState<'disconnected' | 'connecting' | 'connected'>('disconnected');

  // Handle call initiated event
  const handleCallInitiated = useCallback((data: CallInitiatedData) => {
    logger.debug('[useCallPresence] Call initiated:', { callId: data.call_id });

    setActiveCalls(prev => {
      // Check if call already exists (prevent duplicates)
      if (prev.some(call => call.call_id === data.call_id)) {
        return prev;
      }

      return [...prev, {
        call_id: data.call_id,
        from_number: data.from_number,
        to_number: data.to_number,
        did_id: data.did_id,
        status: data.status,
        initiated_at: data.initiated_at,
        duration: 0,
      }];
    });
  }, []);

  // Handle call answered event
  const handleCallAnswered = useCallback((data: CallAnsweredData) => {
    logger.debug('[useCallPresence] Call answered:', { callId: data.call_id });

    setActiveCalls(prev => prev.map(call =>
      call.call_id === data.call_id
        ? {
            ...call,
            status: data.status,
            answered_at: data.answered_at,
            extension_id: data.extension_id,
          }
        : call
    ));
  }, []);

  // Handle call ended event
  const handleCallEnded = useCallback((data: CallEndedData) => {
    logger.debug('[useCallPresence] Call ended:', { callId: data.call_id });

    setActiveCalls(prev => prev.filter(call => call.call_id !== data.call_id));
  }, []);

  // Handle presence updates
  const handlePresenceUpdate = useCallback((members: PresenceMember[]) => {
    logger.debug('[useCallPresence] Online members:', { count: members.length });
    setOnlineMembers(members);
  }, []);

  const handleMemberJoined = useCallback((member: PresenceMember) => {
    logger.debug('[useCallPresence] Member joined:', { memberName: member.name });
    setOnlineMembers(prev => {
      // Prevent duplicates
      if (prev.some(m => m.id === member.id)) {
        return prev;
      }
      return [...prev, member];
    });
  }, []);

  const handleMemberLeft = useCallback((member: PresenceMember) => {
    logger.debug('[useCallPresence] Member left:', { memberName: member.name });
    setOnlineMembers(prev => prev.filter(m => m.id !== member.id));
  }, []);

  // Connect to Echo and subscribe to organization channel
  useEffect(() => {
    if (!isAuthenticated || !user || !token) {
      setConnectionState('disconnected');
      return;
    }

    try {
      setConnectionState('connecting');

      // Connect to Echo if not already connected
      if (!echoService.isConnected()) {
        echoService.connect(token);
      }

      // Subscribe to organization presence channel
      echoService.subscribeToOrganization(user.organization_id, {
        onCallInitiated: handleCallInitiated,
        onCallAnswered: handleCallAnswered,
        onCallEnded: handleCallEnded,
        onPresenceUpdate: handlePresenceUpdate,
        onMemberJoined: handleMemberJoined,
        onMemberLeft: handleMemberLeft,
      });

      setConnectionState('connected');

      // Cleanup on unmount
      return () => {
        echoService.leaveOrganization();
        echoService.disconnect();
        setConnectionState('disconnected');
      };
    } catch (error) {
      logger.error('[useCallPresence] Failed to setup call presence:', { error });
      setConnectionState('disconnected');
    }
  }, [
    isAuthenticated,
    user,
    token,
    handleCallInitiated,
    handleCallAnswered,
    handleCallEnded,
    handlePresenceUpdate,
    handleMemberJoined,
    handleMemberLeft,
  ]);

  // Update call durations every second
  useEffect(() => {
    const interval = setInterval(() => {
      setActiveCalls(prev => prev.map(call => {
        const initiatedTime = new Date(call.initiated_at).getTime();
        const now = Date.now();
        const durationSeconds = Math.floor((now - initiatedTime) / 1000);

        return {
          ...call,
          duration: durationSeconds,
        };
      }));
    }, 1000);

    return () => clearInterval(interval);
  }, []);

  return {
    activeCalls,
    onlineMembers,
    totalActiveCalls: activeCalls.length,
    isConnected: connectionState === 'connected',
    connectionState,
  };
}

/**
 * Format call duration as HH:MM:SS or MM:SS
 */
export function formatCallDuration(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;

  if (hours > 0) {
    return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }

  return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}
