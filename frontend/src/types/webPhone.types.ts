/** Stored in the extension's Cloudonix subscriber profile; the server fills in defaults. */
export interface WebPhoneSettings {
  /** Answer an inbound call 2 seconds after it starts ringing. */
  auto_answer: boolean;
  /** Re-register every 60 seconds. */
  keep_alive: boolean;
  /** Re-register after a call ends. */
  refresh_on_hangup: boolean;
}

export const DEFAULT_WEBPHONE_SETTINGS: WebPhoneSettings = {
  auto_answer: false,
  keep_alive: true,
  refresh_on_hangup: true,
};

export interface WebPhoneConfig {
  sip_username: string;
  sip_password: string;
  sip_domain: string;
  sip_uri: string;
  display_name: string;
  wss_server: string;
  websocket_port: number;
  server_path: string;
  sip_contact: string;
  profile_name: string;
  registration_mode: string;
  country: string;
  /** False in an embed snippet that does not allow call transfer; absent means allowed. */
  transfer_enabled?: boolean;
  settings?: WebPhoneSettings;
}

export interface WebPhoneCallLogEntry {
  to: string;
  session_timestamp: string;
  duration: number;
  duration_formatted: string;
  disposition: string;
}

/** An extension the Web Phone can transfer a call to. */
export interface WebPhoneDirectoryEntry {
  extension_number: string;
  name: string | null;
}
