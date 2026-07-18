// Display formatting — mirrors the PHP helpers (hn, fmt_date, fmt_type) so
// the React pages render identically to the pages they replace.

/** "emily wilson" → "Emily Wilson" (PHP hn()) */
export function personName(name: string): string {
  return name
    .toLowerCase()
    .replace(/(^|[\s\-'])(\p{L})/gu, (m) => m.toUpperCase());
}

/** "2026-07-04" → "04 Jul 2026" (PHP fmt_date()) */
export function fmtDate(iso: string): string {
  const d = new Date(iso.slice(0, 10) + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return iso;
  const mon = d.toLocaleString('en-US', { month: 'short' });
  return `${String(d.getDate()).padStart(2, '0')} ${mon} ${d.getFullYear()}`;
}

/** "2026-07-04" → "Saturday 04 Jul 2026" (parent attendance list) */
export function fmtDateLong(iso: string): string {
  const d = new Date(iso.slice(0, 10) + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return iso;
  return `${d.toLocaleString('en-US', { weekday: 'long' })} ${fmtDate(iso)}`;
}

/** "2026-07-01" → "Jul 2026" (tuition month_covered) */
export function fmtMonth(iso: string): string {
  const d = new Date(iso.slice(0, 10) + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return iso;
  return `${d.toLocaleString('en-US', { month: 'short' })} ${d.getFullYear()}`;
}

/** "2026-07-04 15:05:00" → "04 Jul 2026 3:05 pm" (PHP date('d M Y g:i a')) */
export function fmtDateTime(iso: string): string {
  const d = new Date(iso.replace(' ', 'T'));
  if (Number.isNaN(d.getTime())) return iso;
  const h24 = d.getHours();
  const h12 = h24 % 12 === 0 ? 12 : h24 % 12;
  const ampm = h24 < 12 ? 'am' : 'pm';
  return `${fmtDate(iso)} ${h12}:${String(d.getMinutes()).padStart(2, '0')} ${ampm}`;
}

/** "monthly_tuition" → "Monthly Tuition" (PHP fmt_type()) */
export function paymentType(type: string): string {
  return type
    .split('_')
    .map((w) => (w ? w[0].toUpperCase() + w.slice(1) : w))
    .join(' ');
}

/** 51.25 → "$51.25" */
export function money(amount: number): string {
  return `$${amount.toFixed(2)}`;
}

/** "8015551234" → "801-555-1234" (PHP fmt_phone()) */
export function fmtPhone(phone: string): string {
  const d = phone.replace(/\D/g, '');
  if (d.length !== 10) return phone;
  return `${d.slice(0, 3)}-${d.slice(3, 6)}-${d.slice(6)}`;
}
