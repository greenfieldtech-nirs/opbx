/**
 * Phone Numbers Utilities
 */

import type { RoutingType } from '@/types/api.types';
import { User, Users, Clock, Video, Phone } from 'lucide-react';

/**
 * Get routing type display information (icon, label, badge color)
 */
export function getRoutingTypeDisplay(routingType: RoutingType) {
  switch (routingType) {
    case 'extension':
      return {
        icon: User,
        label: 'Extension',
        color: 'bg-blue-100 text-blue-800 border-blue-200',
      };
    case 'ring_group':
      return {
        icon: Users,
        label: 'Ring Group',
        color: 'bg-purple-100 text-purple-800 border-purple-200',
      };
    case 'business_hours':
      return {
        icon: Clock,
        label: 'Business Hours',
        color: 'bg-green-100 text-green-800 border-green-200',
      };
    case 'conference_room':
      return {
        icon: Video,
        label: 'Conference Room',
        color: 'bg-orange-100 text-orange-800 border-orange-200',
      };
    default:
      return {
        icon: Phone,
        label: routingType,
        color: 'bg-gray-100 text-gray-800 border-gray-200',
      };
  }
}

/**
 * Format routing type for display (e.g., "ring_group" -> "Ring Group")
 */
export function formatRoutingType(routingType: RoutingType): string {
  return getRoutingTypeDisplay(routingType).label;
}

/**
 * Validate E.164 phone number format
 */
export function isValidE164PhoneNumber(phoneNumber: string): boolean {
  const e164Regex = /^\+[1-9]\d{1,14}$/;
  return e164Regex.test(phoneNumber);
}

/**
 * Get phone number validation error message
 */
export function getPhoneNumberValidationError(phoneNumber: string): string | null {
  if (!phoneNumber) {
    return 'Phone number is required';
  }
  if (!phoneNumber.startsWith('+')) {
    return 'Phone number must start with + (E.164 format)';
  }
  if (!isValidE164PhoneNumber(phoneNumber)) {
    return 'Phone number must be in E.164 format (+12125551234)';
  }
  return null;
}
