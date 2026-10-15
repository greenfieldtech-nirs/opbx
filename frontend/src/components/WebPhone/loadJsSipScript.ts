// Bundled (code-split) JsSIP; exposed on window.JsSIP so WebPhone.tsx is unchanged.
export async function loadJsSipScript(): Promise<void> {
  if (typeof window === 'undefined') {
    throw new Error('Cannot load JsSIP outside a browser.');
  }
  if ((window as any).JsSIP) return;
  const mod: any = await import('jssip');
  (window as any).JsSIP = mod.default ?? mod;
}
