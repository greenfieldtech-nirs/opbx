/**
 * LoadingSpinner Component
 *
 * Animated loading spinner for async operations
 * Multiple sizes and optional text label
 *
 * @example
 * <LoadingSpinner size="md" />
 * <LoadingSpinner size="lg" text="Loading calls..." />
 */

import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

interface LoadingSpinnerProps {
  size?: 'sm' | 'md' | 'lg' | 'xl';
  text?: string;
  className?: string;
}

const sizeStyles = {
  sm: 'h-4 w-4',
  md: 'h-6 w-6',
  lg: 'h-8 w-8',
  xl: 'h-12 w-12',
};

const textSizeStyles = {
  sm: 'text-xs',
  md: 'text-sm',
  lg: 'text-base',
  xl: 'text-lg',
};

export function LoadingSpinner({
  size = 'md',
  text,
  className,
}: LoadingSpinnerProps) {
  return (
    <div className={cn('flex flex-col items-center justify-center gap-3', className)}>
      <Loader2
        className={cn('animate-spin text-primary-600', sizeStyles[size])}
      />
      {text && (
        <p className={cn('text-neutral-600', textSizeStyles[size])}>{text}</p>
      )}
    </div>
  );
}
