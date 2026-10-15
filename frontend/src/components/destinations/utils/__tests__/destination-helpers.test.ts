import { describe, expect, it } from 'vitest';
import { transformAiAssistantsToOptions } from '../destination-helpers';

describe('transformAiAssistantsToOptions', () => {
  it('does not print "Ext undefined" for assistants without an extension number', () => {
    const [opt] = transformAiAssistantsToOptions([{ id: 1, name: 'Aria' }]);
    expect(opt.label).toBe('Aria');
  });
  it('keeps the extension prefix when there is a number', () => {
    const [opt] = transformAiAssistantsToOptions([{ id: 1, extension_number: '2001', name: 'Aria' }]);
    expect(opt.label).toBe('Ext 2001 - Aria');
  });
});
