/**
 * The caller as a person reads it: "Alice (1001)" when the SIP identity carries a display name,
 * else the number (the user part of the SIP URI), e.g. "+15551234567" instead of
 * "sip:+15551234567@pbx.example.com".
 */
export function callerLabel(session: any): string {
  const identity = session?.remote_identity;
  const user: string | undefined = identity?.uri?.user;
  const number = user || identity?.uri?.toString?.() || 'Unknown caller';
  const name = String(identity?.display_name ?? '').trim();
  return name && name !== number ? `${name} (${number})` : number;
}

/** A JsSIP session that is still alive (ringing, connecting or connected). */
export function isLive(session: any): boolean {
  return !!session && !(typeof session.isEnded === 'function' && session.isEnded());
}
