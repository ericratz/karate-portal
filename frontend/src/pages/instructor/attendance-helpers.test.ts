import { describe, it, expect } from 'vitest';
import { fmtHeading, sortStudents, type SortKey } from './attendance-helpers';
import type { AttendanceStudent } from '../../api/types';

function student(over: Partial<AttendanceStudent>): AttendanceStudent {
  return {
    id: 1,
    first_name: 'First',
    last_name: 'Last',
    student_type: 'student',
    injury_waiver: false,
    present: false,
    last_attended: null,
    ...over,
  };
}

describe('fmtHeading', () => {
  it('formats an ISO date as "Weekday, D Month YYYY"', () => {
    // 2099-01-15 is a Thursday
    expect(fmtHeading('2099-01-15')).toBe('Thursday, 15 January 2099');
  });

  it('ignores a time component and does not zero-pad the day', () => {
    expect(fmtHeading('2026-07-04T13:45:00')).toBe('Saturday, 4 July 2026');
  });

  it('returns the input unchanged when it is not a valid date', () => {
    expect(fmtHeading('not-a-date')).toBe('not-a-date');
  });
});

describe('sortStudents', () => {
  const a = student({ id: 1, first_name: 'Zoe', last_name: 'Adams', last_attended: '2026-07-01' });
  const b = student({ id: 2, first_name: 'Amy', last_name: 'Baker', last_attended: null });
  const c = student({ id: 3, first_name: 'Bob', last_name: 'Adams', last_attended: '2026-06-01' });

  it('does not mutate the input array', () => {
    const input = [a, b, c];
    const before = [...input];
    sortStudents(input, 'last_name');
    expect(input).toEqual(before);
  });

  it('sorts by "Last First" for last_name', () => {
    const out = sortStudents([a, b, c], 'last_name');
    // Adams,Bob and Adams,Zoe before Baker,Amy; within Adams, Bob < Zoe
    expect(out.map((s) => s.id)).toEqual([3, 1, 2]);
  });

  it('puts never-attended first, then oldest attendance first, for last_attended', () => {
    const out = sortStudents([a, b, c], 'last_attended');
    // b (never) first, then c (2026-06-01) before a (2026-07-01)
    expect(out.map((s) => s.id)).toEqual([2, 3, 1]);
  });

  it('leaves order unchanged for first_name (server already ordered)', () => {
    const out = sortStudents([a, b, c], 'first_name' as SortKey);
    expect(out.map((s) => s.id)).toEqual([1, 2, 3]);
  });
});
