const SIPERB_SCRIPT_URL = 'https://cdn.siperb.com/lib/Siperb-Web-Phone/Web-Phone-latest.umd.min.js';
const SIPERB_GLOBAL = 'Siperb';

export function loadSiperbScript(): Promise<void> {
  return new Promise((resolve, reject) => {
    if (typeof window === 'undefined') {
      reject(new Error('Cannot load Siperb script outside a browser.'));
      return;
    }

    if ((window as any)[SIPERB_GLOBAL]) {
      resolve();
      return;
    }

    const existing = document.querySelector(`script[src="${SIPERB_SCRIPT_URL}"]`);
    if (existing) {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', () => reject(new Error('Failed to load Siperb script.')));
      return;
    }

    const script = document.createElement('script');
    script.src = SIPERB_SCRIPT_URL;
    script.async = true;
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('Failed to load Siperb script.'));
    document.head.appendChild(script);
  });
}
