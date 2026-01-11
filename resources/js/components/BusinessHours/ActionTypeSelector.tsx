import React from 'react';
import { BusinessHoursActionType } from '@/types/business-hours';

interface ActionTypeSelectorProps {
  value: BusinessHoursActionType;
  onChange: (value: BusinessHoursActionType) => void;
  disabled?: boolean;
  className?: string;
}

const actionTypes: Array<{
  value: BusinessHoursActionType;
  label: string;
  description: string;
}> = [
  {
    value: 'extension',
    label: 'Extension',
    description: 'Route calls directly to a specific extension',
  },
  {
    value: 'ring_group',
    label: 'Ring Group',
    description: 'Route calls to a ring group for simultaneous or sequential ringing',
  },
  {
    value: 'ivr_menu',
    label: 'IVR Menu',
    description: 'Route calls to an interactive voice response menu',
  },
];

export function ActionTypeSelector({
  value,
  onChange,
  disabled = false,
  className = '',
}: ActionTypeSelectorProps) {
  return (
    <div className={`space-y-2 ${className}`}>
      <label className="block text-sm font-medium text-gray-700">
        Action Type
      </label>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value as BusinessHoursActionType)}
        disabled={disabled}
        className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
      >
        {actionTypes.map((type) => (
          <option key={type.value} value={type.value}>
            {type.label}
          </option>
        ))}
      </select>
      {actionTypes.find((type) => type.value === value) && (
        <p className="text-sm text-gray-600">
          {actionTypes.find((type) => type.value === value)?.description}
        </p>
      )}
    </div>
  );
}
