const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['day', 86_400_000], ['hour', 3_600_000], ['minute', 60_000],
];

/** "3 hours ago" style text. `now` is injectable so it can be tested. */
export function relativeTime(iso: string, now: number = Date.now()): string {
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return '';
  const diff = t - now;
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
  for (const [unit, ms] of UNITS) {
    if (Math.abs(diff) >= ms) return rtf.format(Math.round(diff / ms), unit);
  }
  return 'just now';
}

export function exactTime(iso: string): string {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('en-MY', { dateStyle: 'medium', timeStyle: 'short' });
}

/** Risk is never colour-only: the word always comes with the number. */
export function riskBand(score: number): 'low' | 'mid' | 'high' {
  return score >= 70 ? 'high' : score >= 40 ? 'mid' : 'low';
}
export function riskWord(score: number): string {
  return { low: 'Lower risk', mid: 'Medium risk', high: 'High risk' }[riskBand(score)];
}
