export type WebPhoneConfigFailure = 'no_extension' | 'cloudonix_not_configured' | 'error';

/** Classify a GET /webphone/config failure: 404 = no extension, 409 + error code = Cloudonix not set up. */
export function classifyConfigError(err: unknown): WebPhoneConfigFailure {
  const response = (err as { response?: { status?: number; data?: { error?: string } } })?.response;
  if (response?.status === 404) return 'no_extension';
  if (response?.status === 409 && response.data?.error === 'cloudonix_not_configured') return 'cloudonix_not_configured';
  return 'error';
}
