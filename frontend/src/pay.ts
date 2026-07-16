// Pure selection → PayPal-items logic for the Pay page, extracted from the
// inline JS of the old parent/pay.php (buildItems / recalculate / itemLabel)
// so it can be unit-tested without the DOM or the PayPal SDK.

import type { PayFee } from './api/types';

/** One line of a payment — the shape api/paypal_create.php expects in `items`. */
export interface PayItem {
  type: string;
  amount: number;
  month_covered?: string;
  reason?: string;
  anonymous?: boolean;
}

/** Everything the user has ticked/typed on the Pay page. */
export interface PaySelection {
  checkedFees: string[]; // keys into the fee table (monthly_tuition, ...)
  tuitionMonth: string; // YYYY-MM-01
  donationChecked: boolean;
  donationAmount: string; // raw input value
  donationAnonymous: boolean;
  customChecked: boolean;
  customAmount: string; // raw input value
  customReason: string;
}

export const emptySelection: PaySelection = {
  checkedFees: [],
  tuitionMonth: '',
  donationChecked: false,
  donationAmount: '',
  donationAnonymous: false,
  customChecked: false,
  customAmount: '',
  customReason: '',
};

/**
 * The items array for api/paypal_create.php. Fixed fees carry their canonical
 * amount; donation/custom only count once a positive amount is entered
 * (matching the old page, which skipped them at 0).
 */
export function buildItems(fees: Record<string, PayFee>, sel: PaySelection): PayItem[] {
  const items: PayItem[] = [];

  for (const key of sel.checkedFees) {
    const fee = fees[key];
    if (!fee) continue;
    const item: PayItem = { type: key, amount: fee.amount };
    if (key === 'monthly_tuition') item.month_covered = sel.tuitionMonth;
    items.push(item);
  }

  if (sel.donationChecked) {
    const amt = parseFloat(sel.donationAmount);
    if (amt > 0) {
      items.push({ type: 'donation', amount: amt, anonymous: sel.donationAnonymous });
    }
  }

  if (sel.customChecked) {
    const amt = parseFloat(sel.customAmount);
    if (amt > 0) {
      items.push({ type: 'other', amount: amt, reason: sel.customReason });
    }
  }

  return items;
}

/** Sum of the built items, rounded to cents (the total the server re-checks). */
export function computeTotal(items: PayItem[]): number {
  const t = items.reduce((sum, i) => sum + i.amount, 0);
  return Math.round(t * 100) / 100;
}

/** Receipt line label for one item. */
export function itemLabel(fees: Record<string, PayFee>, item: PayItem): string {
  if (item.type === 'donation') return 'Donation';
  if (item.type === 'other') return item.reason || 'Other';
  return fees[item.type]?.label ?? item.type;
}

/**
 * "Emily has" / "Emily and Ben have" / "Emily, Ben, and Zoe have" — the
 * subject phrase of the family tuition/auto-pay notices.
 */
export function namesHave(names: string[]): string {
  if (names.length === 0) return '';
  if (names.length === 1) return `${names[0]} has`;
  if (names.length === 2) return `${names[0]} and ${names[1]} have`;
  return `${names.slice(0, -1).join(', ')}, and ${names[names.length - 1]} have`;
}
