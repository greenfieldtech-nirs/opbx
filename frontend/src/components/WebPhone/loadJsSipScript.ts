const JSSIP_CDN_URL = 'https://jssip.net/download/releases/jssip-3.10.0.min.js';
const JSSIP_GLOBAL = 'JsSIP';

export function loadJsSipScript(): Promise<void> {
  return new Promise((resolve, reject) => {
    if (typeof window === 'undefined') {
      reject(new Error('Cannot load JsSIP script outside a browser.'));
      return;
    }

    if ((window as any)[JSSIP_GLOBAL]) {
      resolve();
      return;
    }

    const existing = document.querySelector(`script[src="${JSSIP_CDN_URL}"]`);
    if (existing) {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', () => reject(new Error('Failed to load JsSIP script.')));
      return;
    }

    const script = document.createElement('script');
    script.src = JSSIP_CDN_URL;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('Failed to load JsSIP script.'));
    document.head.appendChild(script);
  });
}
