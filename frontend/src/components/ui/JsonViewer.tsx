import React from 'react';
import JsonView from '@microlink/react-json-view';

interface JsonViewerProps {
  data: any;
  collapsed?: boolean;
  className?: string;
  light?: boolean;
}

export function JsonViewer({ data, collapsed = false, className = '', light = false }: JsonViewerProps) {
  const bgColor = light ? '#f8fafc' : '#0f172a';
  const textColor = light ? '#1e293b' : '#e2e8f0';

  return (
    <div className={`rounded border overflow-x-auto ${className}`} style={{ backgroundColor: bgColor }}>
      <JsonView
        src={data}
        collapseStringsAfterLength={100}
        collapsed={collapsed}
        theme={light ? 'rjv-default' : 'monokai'}
        style={{
          fontSize: '12px',
          backgroundColor: bgColor,
          color: textColor,
        }}
        displayDataTypes={false}
        enableClipboard={true}
      />
    </div>
  );
}

export default JsonViewer;