import { AxiosError } from 'axios';
import { describe, expect, it } from 'vitest';
import { getApiErrorMessage } from '../api';

const err = (data: unknown) =>
  new AxiosError('Request failed with status code 422', '422', undefined, undefined, {
    status: 422, statusText: '', headers: {}, config: {} as never, data,
  });

describe('getApiErrorMessage', () => {
  it('reads the OPBX error envelope, a string error and the Laravel message', () => {
    expect(getApiErrorMessage(err({ error: { message: 'Envelope' } }))).toBe('Envelope');
    expect(getApiErrorMessage(err({ error: 'Plain string' }))).toBe('Plain string');
    expect(getApiErrorMessage(err({ message: 'The name field is required.', errors: {} }))).toBe('The name field is required.');
  });

  it('never hands an object to the toast', () => {
    expect(typeof getApiErrorMessage(err({ error: { code: 'X' } }))).toBe('string');
  });
});
