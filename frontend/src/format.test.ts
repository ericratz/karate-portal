// The formatters must match their PHP counterparts (hn, fmt_date, fmt_type,
// fmt_phone) exactly — the React pages must render identically to the PHP
// pages they replaced.

import { describe, expect, it } from 'vitest';
import { fmtDate, fmtDateLong, fmtMonth, fmtPhone, money, paymentType, personName } from './format';

describe('personName (PHP hn)', () => {
  it('capitalizes each word', () => {
    expect(personName('emily wilson')).toBe('Emily Wilson');
  });
  it('lowercases the rest, like ucwords(strtolower())', () => {
    expect(personName('EmilyEdited')).toBe('Emilyedited');
    expect(personName('MCDONALD')).toBe('Mcdonald');
  });
  it('capitalizes after hyphens and apostrophes', () => {
    expect(personName("mary-jane o'brien")).toBe("Mary-Jane O'Brien");
  });
});

describe('fmtDate (PHP fmt_date, d M Y)', () => {
  it('formats ISO dates', () => {
    expect(fmtDate('2026-07-04')).toBe('04 Jul 2026');
  });
  it('accepts MySQL datetimes', () => {
    expect(fmtDate('2026-06-03 00:00:00')).toBe('03 Jun 2026');
  });
  it('passes garbage through unchanged', () => {
    expect(fmtDate('not-a-date')).toBe('not-a-date');
  });
});

describe('fmtDateLong', () => {
  it('prefixes the weekday', () => {
    expect(fmtDateLong('2026-07-04')).toBe('Saturday 04 Jul 2026');
  });
});

describe('fmtMonth (M Y)', () => {
  it('formats to month + year', () => {
    expect(fmtMonth('2026-07-01')).toBe('Jul 2026');
  });
});

describe('paymentType (PHP fmt_type)', () => {
  it('title-cases snake_case types', () => {
    expect(paymentType('monthly_tuition')).toBe('Monthly Tuition');
    expect(paymentType('belt_test')).toBe('Belt Test');
    expect(paymentType('donation')).toBe('Donation');
  });
});

describe('money', () => {
  it('formats with two decimals', () => {
    expect(money(51.25)).toBe('$51.25');
    expect(money(15)).toBe('$15.00');
  });
});

describe('fmtPhone (PHP fmt_phone)', () => {
  it('formats 10-digit numbers', () => {
    expect(fmtPhone('8015551234')).toBe('801-555-1234');
    expect(fmtPhone('801-555-1234')).toBe('801-555-1234');
  });
  it('passes non-10-digit strings through', () => {
    expect(fmtPhone('12345')).toBe('12345');
  });
});
