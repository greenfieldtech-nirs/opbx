export interface CallerIdentity {
  /** The caller ID: the user part of the SIP URI, e.g. "+15551234567" (or the URI when there is none). */
  id: string;
  /** The caller name, or '' when the call carries none (or only repeats the number). */
  name: string;
}

export function callerIdentity(session: any): CallerIdentity {
  const identity = session?.remote_identity;
  const user: string | undefined = identity?.uri?.user;
  const id = user || identity?.uri?.toString?.() || 'Unknown caller';
  const name = String(identity?.display_name ?? '').trim();
  return { id, name: name === id ? '' : name };
}

/**
 * The caller as a person reads it: "Alice (1001)" when the SIP identity carries a display name,
 * else the number, e.g. "+15551234567" instead of "sip:+15551234567@pbx.example.com".
 */
export function callerLabel(session: any): string {
  const { id, name } = callerIdentity(session);
  return name ? `${name} (${id})` : id;
}

/** A JsSIP session that is still alive (ringing, connecting or connected). */
export function isLive(session: any): boolean {
  return !!session && !(typeof session.isEnded === 'function' && session.isEnded());
}
