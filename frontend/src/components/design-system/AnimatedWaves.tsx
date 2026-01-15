/**
 * AnimatedWaves Component
 *
 * Animated SVG waves background component for login pages
 * Features multiple layered waves with smooth CSS animations
 */

import React from 'react';

interface AnimatedWavesProps {
  className?: string;
}

export default function AnimatedWaves({ className = '' }: AnimatedWavesProps) {
  return (
    <div className={`absolute inset-0 overflow-hidden ${className}`}>
      <svg
        className="absolute bottom-0 w-full h-full"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 1200 600"
        preserveAspectRatio="none"
      >
        {/* Wave 1 - Slow moving, large amplitude */}
        <path
          d="M0,400 C300,300 600,500 1200,400 L1200,600 L0,600 Z"
          fill="rgba(59, 130, 246, 0.1)"
          className="animate-wave-1"
        />

        {/* Wave 2 - Medium speed, different phase */}
        <path
          d="M0,450 C400,350 800,550 1200,450 L1200,600 L0,600 Z"
          fill="rgba(59, 130, 246, 0.15)"
          className="animate-wave-2"
        />

        {/* Wave 3 - Fast moving, small amplitude */}
        <path
          d="M0,500 C500,450 700,550 1200,500 L1200,600 L0,600 Z"
          fill="rgba(59, 130, 246, 0.2)"
          className="animate-wave-3"
        />
      </svg>
    </div>
  );
}