import { Grid3x3, Clock } from 'lucide-react';

export type WebPhoneTab = 'dialer' | 'calls';

interface WebPhoneTabsProps {
  active: WebPhoneTab;
  onChange: (tab: WebPhoneTab) => void;
  disabled?: boolean;
}

const TABS: { id: WebPhoneTab; label: string; Icon: typeof Grid3x3 }[] = [
  { id: 'dialer', label: 'Keypad', Icon: Grid3x3 },
  { id: 'calls', label: 'Recents', Icon: Clock },
];

// Bottom tab bar, iOS/Samsung phone style. Locked (disabled) during an active
// call so the user cannot navigate away from the live dialer.
export function WebPhoneTabs({ active, onChange, disabled }: WebPhoneTabsProps) {
  return (
    <div className="grid grid-cols-2 border-t bg-background" role="tablist">
      {TABS.map(({ id, label, Icon }) => {
        const isActive = active === id;
        return (
          <button
            key={id}
            type="button"
            role="tab"
            aria-selected={isActive}
            disabled={disabled}
            onClick={() => onChange(id)}
            className={`flex flex-col items-center justify-center gap-1 py-2.5 text-[11px] font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed ${
              isActive
                ? 'text-primary'
                : 'text-muted-foreground hover:text-foreground'
            }`}
          >
            <Icon className="h-5 w-5" />
            {label}
          </button>
        );
      })}
    </div>
  );
}

export default WebPhoneTabs;
