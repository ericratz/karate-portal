// Pure helpers for the Take Attendance page — extracted so they can be unit
// tested without rendering the page (the page itself is covered end-to-end by
// Playwright). See attendance-helpers.test.ts.

import type { AttendanceStudent } from '../../api/types';

export type SortKey = 'first_name' | 'last_name' | 'last_attended';

/** "2099-01-15" → "Thursday, 15 January 2099" (PHP date('l, j F Y')) */
export function fmtHeading(iso: string): string {
  const d = new Date(iso.slice(0, 10) + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return iso;
  return `${d.toLocaleString('en-US', { weekday: 'long' })}, ${d.getDate()} ${d.toLocaleString('en-US', { month: 'long' })} ${d.getFullYear()}`;
}

export function sortStudents(list: AttendanceStudent[], sort: SortKey): AttendanceStudent[] {
  const out = [...list];
  if (sort === 'last_name') {
    out.sort((a, b) =>
      `${a.last_name} ${a.first_name}`.localeCompare(`${b.last_name} ${b.first_name}`, undefined, { sensitivity: 'base' }));
  } else if (sort === 'last_attended') {
    // Never-attended first, then oldest attendance first (matches the SQL)
    out.sort((a, b) => {
      if (a.last_attended === b.last_attended) return 0;
      if (!a.last_attended) return -1;
      if (!b.last_attended) return 1;
      return a.last_attended.localeCompare(b.last_attended);
    });
  }
  return out;
}
