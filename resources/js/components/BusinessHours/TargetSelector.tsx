import React, { useEffect, useState } from 'react';
import { BusinessHoursActionType } from '@/types/business-hours';

interface TargetSelectorProps {
  actionType: BusinessHoursActionType;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  className?: string;
}

// Mock data - in real implementation, this would come from API
const mockExtensions = [
  { id: 'ext-101', name: 'John Doe', number: '101' },
  { id: 'ext-102', name: 'Jane Smith', number: '102' },
  { id: 'ext-voicemail', name: 'Voicemail', number: '999' },
];

const mockRingGroups = [
  { id: 'rg-sales', name: 'Sales Team' },
  { id: 'rg-support', name: 'Support Team' },
];

const mockIvrMenus = [
  { id: 'ivr-main', name: 'Main Menu' },
  { id: 'ivr-support', name: 'Support Menu' },
];

export function TargetSelector({
  actionType,
  value,
  onChange,
  disabled = false,
  className = '',
}: TargetSelectorProps) {
  const [options, setOptions] = useState<Array<{ id: string; name: string; number?: string }>>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    setLoading(true);
    
    // Simulate API call delay
    const timer = setTimeout(() => {
      switch (actionType) {
        case 'extension':
          setOptions(mockExtensions);
          break;
        case 'ring_group':
          setOptions(mockRingGroups);
          break;
        case 'ivr_menu':
          setOptions(mockIvrMenus);
          break;
        default:
          setOptions([]);
      }
      setLoading(false);
    }, 300);

    return () => clearTimeout(timer);
  }, [actionType]);

  const getPlaceholder = () => {
    switch (actionType) {
      case 'extension':
        return 'Select an extension...';
      case 'ring_group':
        return 'Select a ring group...';
      case 'ivr_menu':
        return 'Select an IVR menu...';
      default:
        return 'Select a target...';
    }
  };

  const getLabel = () => {
    switch (actionType) {
      case 'extension':
        return 'Extension';
      case 'ring_group':
        return 'Ring Group';
      case 'ivr_menu':
        return 'IVR Menu';
      default:
        return 'Target';
    }
  };

  return (
    <div className={`space-y-2 ${className}`}>
      <label className="block text-sm font-medium text-gray-700">
        {getLabel()}
      </label>
      <select
        value={value}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled || loading}
        className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
      >
        <option value="">
          {loading ? 'Loading...' : getPlaceholder()}
        </option>
        {options.map((option) => (
          <option key={option.id} value={option.id}>
            {option.name} {option.number ? `(${option.number})` : ''}
          </option>
        ))}
      </select>
      {value && options.find((opt) => opt.id === value) && (
        <p className="text-sm text-gray-600">
          Selected: {options.find((opt) => opt.id === value)?.name}
        </p>
      )}
    </div>
  );
}
