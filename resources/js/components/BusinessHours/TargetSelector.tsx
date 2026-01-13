interface TargetSelectorProps {
  actionType: string;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
  className?: string;
  extensions?: Array<{ id: string; name: string; number?: string }>;
  ringGroups?: Array<{ id: string; name: string }>;
  ivrMenus?: Array<{ id: string; name: string }>;
}

export function TargetSelector({
  actionType,
  value,
  onChange,
  disabled = false,
  className = '',
  extensions = [],
  ringGroups = [],
  ivrMenus = [],
}: TargetSelectorProps) {
  const getOptions = () => {
    switch (actionType) {
      case 'extension':
        return extensions;
      case 'ring_group':
        return ringGroups;
      case 'ivr_menu':
        return ivrMenus;
      default:
        return [];
    }
  };

  const options = getOptions();

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
        disabled={disabled}
        className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
      >
        <option value="">
          {getPlaceholder()}
        </option>
        {options.map((option) => (
          <option key={option.id} value={option.id}>
            {option.name} {(option as any).number ? `(${(option as any).number})` : ''}
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
