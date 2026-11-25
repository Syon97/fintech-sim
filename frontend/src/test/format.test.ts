import { relativeTime, riskBand, riskWord } from '../format';

const now = Date.parse('2026-01-10T12:00:00Z');

describe('relativeTime', () => {
  it('says hours', () => expect(relativeTime('2026-01-10T09:00:00Z', now)).toBe('3 hours ago'));
  it('says yesterday', () => expect(relativeTime('2026-01-09T12:00:00Z', now)).toBe('yesterday'));
  it('says just now under a minute', () => expect(relativeTime('2026-01-10T11:59:40Z', now)).toBe('just now'));
  it('is empty for junk', () => expect(relativeTime('nope', now)).toBe(''));
});

describe('risk', () => {
  it('bands', () => { expect(riskBand(10)).toBe('low'); expect(riskBand(40)).toBe('mid'); expect(riskBand(70)).toBe('high'); });
  it('always has a word', () => expect(riskWord(85)).toBe('High risk'));
});
