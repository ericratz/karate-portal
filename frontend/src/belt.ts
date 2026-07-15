// Belt/homework link helpers — TS port of includes/belt_helpers.php's
// hw_index_url() plus the fixed external URLs the dashboard buttons use.

export const HW_INDEX_ADULT = 'https://noji.com/karate/class/homework/homework.php';
export const HW_INDEX_YOUTH = 'https://noji.com/karate/class/homework/youth_homework.php';
export const TEST_INFO_URL = 'https://noji.com/karate/testing/testing.php';

function ageFromDob(dob: string): number {
  const d = new Date(dob.slice(0, 10) + 'T00:00:00');
  if (Number.isNaN(d.getTime())) return 99;
  const now = new Date();
  let age = now.getFullYear() - d.getFullYear();
  const m = now.getMonth() - d.getMonth();
  if (m < 0 || (m === 0 && now.getDate() < d.getDate())) age--;
  return age;
}

/** Youth homework index for under-16s, adult index otherwise (PHP hw_index_url). */
export function hwIndexUrl(dob: string | null): string {
  if (dob && ageFromDob(dob) < 16) return HW_INDEX_YOUTH;
  return HW_INDEX_ADULT;
}

/** Under 18 → guardian signature required on the waiver. */
export function isMinor(dob: string | null): boolean {
  return dob !== null && ageFromDob(dob) < 18;
}
