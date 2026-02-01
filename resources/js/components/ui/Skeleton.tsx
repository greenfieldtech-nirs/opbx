import React from 'react';

interface SkeletonProps {
  className?: string;
  variant?: 'text' | 'circular' | 'rectangular';
  width?: string | number;
  height?: string | number;
}

export function Skeleton({ 
  className = '', 
  variant = 'text',
  width,
  height,
}: SkeletonProps) {
  const variantClasses = {
    text: 'rounded',
    circular: 'rounded-full',
    rectangular: 'rounded-md',
  };

  return (
    <div
      className={`animate-pulse bg-gray-200 ${variantClasses[variant]} ${className}`}
      style={{
        width: width ? (typeof width === 'number' ? `${width}px` : width) : undefined,
        height: height ? (typeof height === 'number' ? `${height}px` : height) : undefined,
      }}
    />
  );
}

interface TableSkeletonProps {
  rows?: number;
  columns?: number;
  showHeader?: boolean;
  columnWidths?: string[];
}

export function TableSkeleton({ 
  rows = 5, 
  columns = 4,
  showHeader = true,
  columnWidths,
}: TableSkeletonProps) {
  const defaultColumnWidths = Array(columns).fill('100px');
  const widths = columnWidths || defaultColumnWidths;

  return (
    <div className="space-y-3">
      {/* Header row */}
      {showHeader && (
        <div className="flex space-x-4 pb-2 border-b">
          {widths.map((width, index) => (
            <Skeleton 
              key={`header-${index}`} 
              width={width} 
              height="20px" 
              variant="rectangular"
            />
          ))}
        </div>
      )}
      
      {/* Data rows */}
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div key={`row-${rowIndex}`} className="flex space-x-4 py-3 border-b">
          {widths.map((width, colIndex) => (
            <Skeleton 
              key={`cell-${rowIndex}-${colIndex}`} 
              width={width} 
              height="24px" 
              variant="text"
            />
          ))}
        </div>
      ))}
    </div>
  );
}

interface CardSkeletonProps {
  hasHeader?: boolean;
  hasFooter?: boolean;
  headerWidth?: string;
  paragraphLines?: number;
}

export function CardSkeleton({ 
  hasHeader = true, 
  hasFooter = true,
  headerWidth = '40%',
  paragraphLines = 3,
}: CardSkeletonProps) {
  return (
    <div className="bg-white shadow rounded-lg p-6 space-y-4">
      {hasHeader && (
        <div className="space-y-2">
          <Skeleton width={headerWidth} height="24px" variant="rectangular" />
          <Skeleton width="60%" height="16px" variant="text" />
        </div>
      )}
      
      <div className="space-y-3">
        {Array.from({ length: paragraphLines }).map((_, index) => (
          <Skeleton 
            key={`line-${index}`} 
            width={index === paragraphLines - 1 ? '80%' : '100%'} 
            height="16px" 
            variant="text"
          />
        ))}
      </div>
      
      {hasFooter && (
        <div className="flex justify-end space-x-2 pt-2">
          <Skeleton width="80px" height="36px" variant="rectangular" />
          <Skeleton width="80px" height="36px" variant="rectangular" />
        </div>
      )}
    </div>
  );
}

interface FormSkeletonProps {
  fieldCount?: number;
  showSubmit?: boolean;
}

export function FormSkeleton({ 
  fieldCount = 5,
  showSubmit = true,
}: FormSkeletonProps) {
  return (
    <div className="bg-white shadow rounded-lg p-6 space-y-6">
      {/* Form title */}
      <Skeleton width="30%" height="28px" variant="rectangular" />
      <Skeleton width="50%" height="16px" variant="text" />
      
      {/* Form fields */}
      <div className="space-y-4">
        {Array.from({ length: fieldCount }).map((_, index) => (
          <div key={`field-${index}`} className="space-y-2">
            <Skeleton width="20%" height="16px" variant="text" />
            <Skeleton width="100%" height="40px" variant="rectangular" />
          </div>
        ))}
      </div>
      
      {/* Submit button */}
      {showSubmit && (
        <div className="flex justify-end pt-4">
          <Skeleton width="120px" height="40px" variant="rectangular" />
        </div>
      )}
    </div>
  );
}

interface ListSkeletonProps {
  items?: number;
  showAvatar?: boolean;
  avatarSize?: number;
}

export function ListSkeleton({ 
  items = 5,
  showAvatar = true,
  avatarSize = 40,
}: ListSkeletonProps) {
  return (
    <div className="space-y-3">
      {Array.from({ length: items }).map((_, index) => (
        <div 
          key={`list-item-${index}`} 
          className="flex items-center space-x-3 p-3 bg-white rounded-lg shadow"
        >
          {showAvatar && (
            <Skeleton 
              width={avatarSize} 
              height={avatarSize} 
              variant="circular" 
            />
          )}
          <div className="flex-1 space-y-2">
            <Skeleton width="40%" height="16px" variant="text" />
            <Skeleton width="70%" height="14px" variant="text" />
          </div>
          <Skeleton width="60px" height="24px" variant="rectangular" />
        </div>
      ))}
    </div>
  );
}

interface StatsSkeletonProps {
  statCount?: number;
}

export function StatsSkeleton({ statCount = 4 }: StatsSkeletonProps) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
      {Array.from({ length: statCount }).map((_, index) => (
        <div 
          key={`stat-${index}`} 
          className="bg-white rounded-lg shadow p-6 space-y-2"
        >
          <Skeleton width="50%" height="14px" variant="text" />
          <Skeleton width="80%" height="32px" variant="text" />
          <Skeleton width="60%" height="12px" variant="text" />
        </div>
      ))}
    </div>
  );
}

// Default export with all skeleton components
export default {
  Skeleton,
  TableSkeleton,
  CardSkeleton,
  FormSkeleton,
  ListSkeleton,
  StatsSkeleton,
};
