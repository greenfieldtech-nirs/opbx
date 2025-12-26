/**
 * Global type definitions for window object
 */

import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

export {};
