import { describe, expect, it } from 'vitest';
import { buildItems, computeTotal, emptySelection, itemLabel, namesHave } from './pay';
import type { PayFee } from './api/types';

const fees: Record<string, PayFee> = {
  monthly_tuition: { label: 'Monthly Tuition', amount: 30 },
  registration: { label: 'Registration Fee', amount: 15 },
  belt_test: { label: 'Belt Test Fee', amount: 10 },
};

describe('buildItems', () => {
  it('returns nothing for an empty selection', () => {
    expect(buildItems(fees, emptySelection)).toEqual([]);
  });

  it('carries the canonical amount for checked fees', () => {
    const items = buildItems(fees, {
      ...emptySelection,
      checkedFees: ['registration', 'belt_test'],
    });
    expect(items).toEqual([
      { type: 'registration', amount: 15 },
      { type: 'belt_test', amount: 10 },
    ]);
  });

  it('attaches month_covered to monthly tuition only', () => {
    const items = buildItems(fees, {
      ...emptySelection,
      checkedFees: ['monthly_tuition', 'registration'],
      tuitionMonth: '2026-08-01',
    });
    expect(items[0]).toEqual({ type: 'monthly_tuition', amount: 30, month_covered: '2026-08-01' });
    expect(items[1]).not.toHaveProperty('month_covered');
  });

  it('ignores fee keys not present in the fee table', () => {
    const items = buildItems(fees, { ...emptySelection, checkedFees: ['bogus'] });
    expect(items).toEqual([]);
  });

  it('includes a donation only when a positive amount is entered', () => {
    const base = { ...emptySelection, donationChecked: true };
    expect(buildItems(fees, base)).toEqual([]);
    expect(buildItems(fees, { ...base, donationAmount: '0' })).toEqual([]);
    expect(buildItems(fees, { ...base, donationAmount: '25.50', donationAnonymous: true })).toEqual([
      { type: 'donation', amount: 25.5, anonymous: true },
    ]);
  });

  it('includes a custom amount only when positive, with its reason', () => {
    const base = { ...emptySelection, customChecked: true, customReason: 'Sparring gear' };
    expect(buildItems(fees, base)).toEqual([]);
    expect(buildItems(fees, { ...base, customAmount: '42' })).toEqual([
      { type: 'other', amount: 42, reason: 'Sparring gear' },
    ]);
  });
});

describe('computeTotal', () => {
  it('is zero for no items', () => {
    expect(computeTotal([])).toBe(0);
  });

  it('sums item amounts and rounds to cents', () => {
    expect(
      computeTotal([
        { type: 'monthly_tuition', amount: 30 },
        { type: 'donation', amount: 0.1 },
        { type: 'other', amount: 0.2 },
      ]),
    ).toBe(30.3); // 0.1 + 0.2 floating-point noise must not leak into the total
  });
});

describe('itemLabel', () => {
  it('labels fixed fees from the fee table', () => {
    expect(itemLabel(fees, { type: 'monthly_tuition', amount: 30 })).toBe('Monthly Tuition');
  });

  it('labels donations and custom items', () => {
    expect(itemLabel(fees, { type: 'donation', amount: 5 })).toBe('Donation');
    expect(itemLabel(fees, { type: 'other', amount: 5, reason: 'Gear' })).toBe('Gear');
    expect(itemLabel(fees, { type: 'other', amount: 5, reason: '' })).toBe('Other');
  });

  it('falls back to the raw type for unknown keys', () => {
    expect(itemLabel(fees, { type: 'mystery', amount: 5 })).toBe('mystery');
  });
});

describe('namesHave', () => {
  it('handles one, two, and three+ names', () => {
    expect(namesHave([])).toBe('');
    expect(namesHave(['Emily'])).toBe('Emily has');
    expect(namesHave(['Emily', 'Ben'])).toBe('Emily and Ben have');
    expect(namesHave(['Emily', 'Ben', 'Zoe'])).toBe('Emily, Ben, and Zoe have');
  });
});
