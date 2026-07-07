export interface PaginatedResponseMeta {
  current_page: number;
  from: number | null;
  to: number | null;
  last_page: number;
  per_page: number;
  total: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: PaginatedResponseMeta;
}
