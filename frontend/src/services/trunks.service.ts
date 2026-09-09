/**
 * Trunks Service
 *
 * Manages SIP trunk CRUD operations via /v1/trunks
 */

import api from './api';

export type TrunkDirection = 'inbound' | 'outbound' | 'public-inbound' | 'public-outbound';
export type TrunkTransport = 'udp' | 'tcp' | 'tls';

export interface Trunk {
  id: number;
  uuid: string;
  name: string;
  direction: TrunkDirection;
  ip: string;
  port: number | string;
  transport: TrunkTransport;
  prefix: string | null;
  active: boolean;
  created_at: string | null;
  has_credentials: boolean;
  username: string | null;
  overwrite_from: boolean;
  in_use_by: string[];
}

export interface CreateTrunkRequest {
  name: string;
  ip: string;
  port: number;
  transport: TrunkTransport;
  direction: 'inbound' | 'outbound';
  prefix?: string;
  username?: string;
  password?: string;
  overwrite_from?: boolean;
}

export interface UpdateTrunkRequest {
  ip?: string;
  port?: number;
  transport?: TrunkTransport;
  prefix?: string;
  username?: string;
  password?: string;
  overwrite_from?: boolean;
}

export const trunksService = {
  /**
   * List trunks, optionally filtered by direction
   * GET /trunks
   */
  listTrunks: (direction?: TrunkDirection): Promise<Trunk[]> => {
    return api.get<{ data: Trunk[] }>('/trunks', {
      params: direction ? { direction } : undefined,
    }).then(res => res.data.data);
  },

  /**
   * Get a single trunk
   * GET /trunks/{id}
   */
  getTrunk: (id: number): Promise<Trunk> => {
    return api.get<{ data: Trunk }>(`/trunks/${id}`).then(res => res.data.data);
  },

  /**
   * Create a trunk
   * POST /trunks
   */
  createTrunk: (data: CreateTrunkRequest): Promise<Trunk> => {
    return api.post<{ data: Trunk }>('/trunks', data).then(res => res.data.data);
  },

  /**
   * Update a trunk (name and direction are immutable)
   * PUT /trunks/{id}
   */
  updateTrunk: (id: number, data: UpdateTrunkRequest): Promise<Trunk> => {
    return api.put<{ data: Trunk }>(`/trunks/${id}`, data).then(res => res.data.data);
  },

  /**
   * Delete a trunk
   * DELETE /trunks/{id}
   */
  deleteTrunk: (id: number): Promise<void> => {
    return api.delete(`/trunks/${id}`).then(() => undefined);
  },
};
