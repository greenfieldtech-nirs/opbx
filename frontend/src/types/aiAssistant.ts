/**
 * AI Assistant Provider Types
 */

export type ProviderProtocol = 'sip' | 'websocket' | 'dummy';

export interface ProviderConfigField {
  name: string;
  label: string;
  type: string;
  required: boolean;
  placeholder?: string;
  description?: string;
  validation_rules: string[];
  read_only?: boolean;
  default_value?: string;
}

export interface ProviderDefinition {
  key: string;
  name: string;
  protocol: ProviderProtocol;
  url_template: string | null;
  config_fields: ProviderConfigField[];
  description?: string;
}

export interface ProvidersResponse {
  data: {
    providers: ProviderDefinition[];
    grouped: {
      sip: ProviderDefinition[];
      websocket: ProviderDefinition[];
      dummy: ProviderDefinition[];
    };
    protocols: ProviderProtocol[];
  };
}

export interface ProviderResponse {
  data: ProviderDefinition;
}

export interface AiAssistantConfiguration {
  protocol?: ProviderProtocol;
  provider?: string;
  phone_number?: string;
  // WebSocket-specific fields
  bot_id?: string;
  auth_token?: string;
  api_key?: string;
  assistant_id?: string;
  agent_id?: string;
  agent_uuid?: string;
  app_id?: string;
  workspace_id?: string;
  project_id?: string;
  websocket_endpoint?: string;
  [key: string]: string | undefined;
}
