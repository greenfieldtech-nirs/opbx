/**
 * Command Component
 *
 * A command menu component with search and keyboard navigation
 * Built without external dependencies, uses native React patterns
 */

import * as React from 'react';
import { cn } from '@/lib/utils';
import { Search } from 'lucide-react';

interface CommandContextValue {
  search: string;
  setSearch: (search: string) => void;
}

const CommandContext = React.createContext<CommandContextValue | undefined>(undefined);

function useCommand() {
  const context = React.useContext(CommandContext);
  if (!context) {
    throw new Error('Command components must be used within Command');
  }
  return context;
}

// Command Root
interface CommandProps extends React.HTMLAttributes<HTMLDivElement> {
  children: React.ReactNode;
}

const Command = React.forwardRef<HTMLDivElement, CommandProps>(
  ({ className, children, ...props }, ref) => {
    const [search, setSearch] = React.useState('');

    return (
      <CommandContext.Provider value={{ search, setSearch }}>
        <div
          ref={ref}
          className={cn(
            'flex h-full w-full flex-col overflow-hidden rounded-md bg-popover text-popover-foreground',
            className
          )}
          {...props}
        >
          {children}
        </div>
      </CommandContext.Provider>
    );
  }
);
Command.displayName = 'Command';

// Command Input
interface CommandInputProps extends Omit<React.InputHTMLAttributes<HTMLInputElement>, 'value' | 'onChange'> {
  value?: string;
  onValueChange?: (value: string) => void;
}

const CommandInput = React.forwardRef<HTMLInputElement, CommandInputProps>(
  ({ className, value: externalValue, onValueChange, ...props }, ref) => {
    const { search, setSearch } = useCommand();

    // Support both controlled (external) and uncontrolled (internal) modes
    const inputValue = externalValue !== undefined ? externalValue : search;
    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
      const newValue = e.target.value;
      if (onValueChange) {
        onValueChange(newValue);
      } else {
        setSearch(newValue);
      }
    };

    return (
      <div className="flex items-center border-b px-3" data-command-input-wrapper="">
        <Search className="mr-2 h-4 w-4 shrink-0 opacity-50" />
        <input
          ref={ref}
          value={inputValue}
          onChange={handleChange}
          className={cn(
            'flex h-11 w-full rounded-md bg-transparent py-3 text-sm outline-none',
            'placeholder:text-muted-foreground disabled:cursor-not-allowed disabled:opacity-50',
            className
          )}
          {...props}
        />
      </div>
    );
  }
);
CommandInput.displayName = 'CommandInput';

// Command List
interface CommandListProps extends React.HTMLAttributes<HTMLDivElement> {
  children: React.ReactNode;
}

const CommandList = React.forwardRef<HTMLDivElement, CommandListProps>(
  ({ className, children, ...props }, ref) => {
    return (
      <div
        ref={ref}
        className={cn('max-h-[300px] overflow-y-auto overflow-x-hidden', className)}
        {...props}
      >
        {children}
      </div>
    );
  }
);
CommandList.displayName = 'CommandList';

// Command Empty
interface CommandEmptyProps extends React.HTMLAttributes<HTMLDivElement> {
  children: React.ReactNode;
}

const CommandEmpty = React.forwardRef<HTMLDivElement, CommandEmptyProps>(
  ({ className, children, ...props }, ref) => {
    return (
      <div
        ref={ref}
        className={cn('py-6 text-center text-sm text-muted-foreground', className)}
        {...props}
      >
        {children}
      </div>
    );
  }
);
CommandEmpty.displayName = 'CommandEmpty';

// Command Group
interface CommandGroupProps extends React.HTMLAttributes<HTMLDivElement> {
  heading?: string;
  children: React.ReactNode;
}

const CommandGroup = React.forwardRef<HTMLDivElement, CommandGroupProps>(
  ({ className, heading, children, ...props }, ref) => {
    return (
      <div
        ref={ref}
        className={cn(
          'overflow-hidden p-1 text-foreground',
          '[&_[data-command-group-heading]]:px-2 [&_[data-command-group-heading]]:py-1.5',
          '[&_[data-command-group-heading]]:text-xs [&_[data-command-group-heading]]:font-medium',
          '[&_[data-command-group-heading]]:text-muted-foreground',
          className
        )}
        {...props}
      >
        {heading && (
          <div data-command-group-heading="" className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
            {heading}
          </div>
        )}
        {children}
      </div>
    );
  }
);
CommandGroup.displayName = 'CommandGroup';

// Command Item
interface CommandItemProps extends React.HTMLAttributes<HTMLDivElement> {
  onSelect?: () => void;
  disabled?: boolean;
  children: React.ReactNode;
}

const CommandItem = React.forwardRef<HTMLDivElement, CommandItemProps>(
  ({ className, onSelect, disabled, children, ...props }, ref) => {
    return (
      <div
        ref={ref}
        role="option"
        aria-disabled={disabled}
        className={cn(
          'relative flex cursor-pointer select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none',
          'hover:bg-accent hover:text-accent-foreground',
          'data-[disabled=true]:pointer-events-none data-[disabled=true]:opacity-50',
          disabled && 'pointer-events-none opacity-50',
          className
        )}
        onClick={disabled ? undefined : onSelect}
        data-disabled={disabled}
        {...props}
      >
        {children}
      </div>
    );
  }
);
CommandItem.displayName = 'CommandItem';

export {
  Command,
  CommandInput,
  CommandList,
  CommandEmpty,
  CommandGroup,
  CommandItem,
};
