import React from 'react';
import JsonView from '@microlink/react-json-view';

interface JsonViewerProps {
  data: any;
  collapsed?: boolean;
  className?: string;
}

export function JsonViewer({ data, collapsed = false, className = '' }: JsonViewerProps) {
  return (
    <div className={`bg-slate-900 p-3 rounded border ${className}`}>
      <JsonView
        src={data}
        theme="dark"
        collapseStringsAfterLength={100}
        collapsed={collapsed}
        style={{
          fontSize: '12px',
          backgroundColor: '#0f172a', // slate-900
        }}
      />
    </div>
  );
}

export default JsonViewer;