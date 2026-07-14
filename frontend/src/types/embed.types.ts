export type EmbedIconPosition =
  | 'bottom-right'
  | 'bottom-left'
  | 'top-right'
  | 'top-left';

export interface EmbedTokenConfig {
  allowed_domains: string[];
  icon_position: EmbedIconPosition;
  icon_background_color: string;
  last_used_at: string | null;
}

export interface EmbedRegenerateResponse {
  token: string;
  snippet: string;
}
