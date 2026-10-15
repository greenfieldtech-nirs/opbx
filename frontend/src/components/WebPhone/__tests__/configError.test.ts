import { describe, expect, it } from 'vitest';
import { classifyConfigError, isAnsweredDisposition } from '../configError';

describe('isAnsweredDisposition', () => {
  it('counts ANSWER and CONNECTED as answered', () => {
    expect(isAnsweredDisposition('ANSWER')).toBe(true);
    expect(isAnsweredDisposition('connected')).toBe(true);
    expect(isAnsweredDisposition('NO_ANSWER')).toBe(false);
    expect(isAnsweredDisposition(undefined)).toBe(false);
  });
});

describe('classifyConfigError', () => {
  it('maps 404 to no_extension', () => {
    expect(classifyConfigError({ response: { status: 404 } })).toBe('no_extension');
  });
  it('maps 409 cloudonix_not_configured', () => {
    expect(classifyConfigError({ response: { status: 409, data: { error: 'cloudonix_not_configured' } } })).toBe('cloudonix_not_configured');
  });
  it('maps anything else to error', () => {
    expect(classifyConfigError({ response: { status: 500 } })).toBe('error');
    expect(classifyConfigError(new Error('x'))).toBe('error');
  });
});
