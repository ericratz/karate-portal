// hwIndexUrl / isMinor must match belt_helpers.php's age boundaries:
// under 16 → youth homework index, under 18 → guardian signature required.

import { describe, expect, it } from 'vitest';
import { HW_INDEX_ADULT, HW_INDEX_YOUTH, hwIndexUrl, isMinor } from './belt';

function dobYearsAgo(years: number): string {
  const d = new Date();
  d.setFullYear(d.getFullYear() - years);
  d.setDate(d.getDate() - 1); // clearly past the birthday
  return d.toISOString().slice(0, 10);
}

describe('hwIndexUrl (PHP hw_index_url)', () => {
  it('returns the youth index for under-16s', () => {
    expect(hwIndexUrl(dobYearsAgo(10))).toBe(HW_INDEX_YOUTH);
    expect(hwIndexUrl(dobYearsAgo(15))).toBe(HW_INDEX_YOUTH);
  });
  it('returns the adult index at 16 and older', () => {
    expect(hwIndexUrl(dobYearsAgo(16))).toBe(HW_INDEX_ADULT);
    expect(hwIndexUrl(dobYearsAgo(40))).toBe(HW_INDEX_ADULT);
  });
  it('treats a missing DOB as adult', () => {
    expect(hwIndexUrl(null)).toBe(HW_INDEX_ADULT);
  });
});

describe('isMinor', () => {
  it('is true under 18', () => {
    expect(isMinor(dobYearsAgo(17))).toBe(true);
  });
  it('is false at 18 and older', () => {
    expect(isMinor(dobYearsAgo(18))).toBe(false);
  });
  it('is false with no DOB (matches the waiver page fallback)', () => {
    expect(isMinor(null)).toBe(false);
  });
});
