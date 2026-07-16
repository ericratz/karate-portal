// Response shapes of the portal/api/v1 endpoints — one interface per payload,
// mirroring what the PHP side serializes (see includes/family.php and
// api/v1/parent/*). Dates are the raw YYYY-MM-DD (or datetime) strings from
// MySQL; formatting happens in the UI.

export interface Me {
  user_id: number;
  username: string;
  role: string;
  csrf_token: string;
}

export interface NextRank {
  kyu_dan: string;
  name: string;
  hw_url: string | null;
  test_url: string | null;
}

export interface FamilyOwnStudent {
  id: number;
  first_name: string;
  last_name: string;
  student_type: string;
  date_of_birth: string | null;
  injury_waiver: boolean;
}

export interface FamilyChild extends FamilyOwnStudent {
  kyu_dan: string | null;
  last_attendance: string | null;
  last_payment: { date: string; type: string } | null;
  next_rank: NextRank | null;
}

export interface Family {
  own_student: FamilyOwnStudent | null;
  children: FamilyChild[];
}

// GET /parent/student.php — one family member's dashboard
export interface StudentProfile {
  id: number;
  first_name: string;
  last_name: string;
  date_of_birth: string | null;
  phone: string | null;
  email: string;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  street_address: string | null;
  city_state_zip: string | null;
  uniform_size: string | null;
  belt_size: string | null;
  medical_note: string | null;
  registration_date: string | null;
  student_type: string;
  injury_waiver: boolean;
  injury_waiver_date: string | null;
}

export interface AttendanceChartMonth {
  month: string;      // YYYY-MM
  label: string;      // e.g. "Jul 2026"
  count: number;
  ranks: string[] | null; // rank names achieved that month, if any
}

export interface StudentDashboard {
  student: StudentProfile;
  rank: { name: string; kyu_dan: string; rank_id: number } | null;
  next_rank: NextRank | null;
  att_summary: { attended: number; total: number };
  recent_attendance: { session_date: string; class_type: string }[];
  recent_payments: {
    payment_date: string;
    payment_type: string;
    payment_method: string;
    amount: string | number;
    month_covered: string | null;
  }[];
  recent_belt_tests: {
    test_date: string;
    kyu_dan: string;
    result: string;
    score: string | number | null;
    fee_paid: string | number;
    belt_awarded: string | number;
  }[];
  rank_history: { rank_id: number; kyu_dan: string; achieved_date: string | null }[];
  active_waivers: string[];
  has_autopay: boolean;
  attendance_chart: AttendanceChartMonth[];
}

// POST /parent/profile.php
export interface ProfileUpdate {
  student_id: number;
  first_name: string;
  last_name: string;
  date_of_birth?: string;
  phone?: string;
  email?: string;
  emergency_contact_name?: string;
  emergency_contact_phone?: string;
  street_address?: string;
  city_state_zip?: string;
  uniform_size?: string;
  belt_size?: string;
  medical_note?: string;
}

export interface ProfileSaved {
  saved: boolean;
  student: StudentProfile;
}

export interface StudentRef {
  id: number;
  first_name: string;
  last_name: string;
}

// GET /parent/attendance.php
export interface AttendanceHistory {
  student: StudentRef;
  total_attended: number;
  dates: string[];
}

// GET /parent/payments.php
export interface PaymentRow {
  payment_date: string;
  payment_type: string;
  payment_method: string;
  amount: number;
  month_covered: string | null;
}

export interface PaymentHistory {
  student: StudentRef;
  years: number[];
  year: number | null;
  payments: PaymentRow[];
  filtered_total: number;
  total_paid: number;
}

// GET /parent/belt_tests.php
export interface BeltTestRow {
  test_date: string;
  rank_name: string;
  kyu_dan: string;
  result: string;
  score: number | null;
  fee_paid: boolean;
  belt_awarded: boolean;
}

export interface BeltTestHistory {
  student: StudentRef;
  tests: BeltTestRow[];
  passed: number;
  pending: number;
}

// GET/POST /parent/waiver.php
export interface WaiverSubmission {
  print_name: string;
  signature: string | null;
  signed_date: string | null;
  guardian_signature: string | null;
  guardian_signed_date: string | null;
  date_of_birth: string | null;
  cell_phone: string | null;
  home_phone: string | null;
  email: string | null;
  street_address: string | null;
  city_state_zip: string | null;
  mailing_address: string | null;
  mailing_city_state_zip: string | null;
}

export interface WaiverStatus {
  student: StudentRef;
  signed: boolean;
  signed_date: string | null;
  is_minor: boolean;
  submission: WaiverSubmission | null;
  prefill: {
    print_name: string;
    date_of_birth: string | null;
    cell_phone: string | null;
    email: string | null;
    street_address: string | null;
    city_state_zip: string | null;
  };
}

export interface WaiverSign {
  student_id: number;
  print_name: string;
  signature?: string;
  signed_date?: string;
  guardian_signature?: string;
  guardian_signed_date?: string;
  date_of_birth?: string;
  cell_phone: string;
  home_phone?: string;
  email: string;
  street_address: string;
  city_state_zip: string;
  mailing_address?: string;
  mailing_city_state_zip?: string;
  i_agree: boolean;
}

export interface WaiverSigned {
  signed: boolean;
  signed_date: string;
}

// GET /parent/pay.php — payment page context
export interface PayFee {
  label: string;
  amount: number;
}

export interface PayFamilyMember {
  id: number;
  name: string;
}

export interface PayMonthOption {
  value: string; // YYYY-MM-01
  label: string; // e.g. "July 2026"
}

export interface PayContext {
  family: PayFamilyMember[];
  own_id: number;
  fees: Record<string, PayFee>;
  monthly_fee: number;
  tuition_paid_ids: number[];
  paid_months_by_student: Record<string, string[]>;
  reg_paid_ids: number[];
  autopay_active_ids: number[];
  month_options: PayMonthOption[];
  current_month_value: string;
  next_month_value: string;
  paypal_client_id: string;
}

// POST /parent/subscription.php
export interface SubscriptionCreated {
  approve_url: string;
}

export interface SubscriptionCancelled {
  cancelled: boolean;
}

// ── api/v1/instructor ────────────────────────────────────────────────────

// GET /instructor/dashboard.php
export interface InstructorDashboardData {
  recent_sessions: { id: number; session_date: string; class_type: string }[];
  recent_belt_tests: {
    id: number;
    test_date: string;
    result: string;
    student_id: number;
    student: string;
    kyu_dan: string;
  }[];
  has_more_tests: boolean;
  own_student_id: number;
  has_children: boolean;
}

// GET /instructor/roster.php
export interface RosterStudent {
  id: number;
  first_name: string;
  last_name: string;
  student_type: string;
  active: boolean;
  active_override: boolean;
  injury_waiver: boolean;
  medical_note: string | null;
  kyu_dan: string | null;
  has_login: boolean;
  last_attended: string | null;
}

export interface RosterData {
  students: RosterStudent[];
  ranks: string[];
}

// GET /instructor/attendance.php
export interface AttendanceStudent {
  id: number;
  first_name: string;
  last_name: string;
  student_type: string;
  injury_waiver: boolean;
  present: boolean;
  last_attended: string | null;
}

export interface AttendanceContext {
  date: string;
  session_exists: boolean;
  class_type: string;
  students: AttendanceStudent[];
}

export interface AttendanceSaved {
  saved: number;
  removed: boolean;
}

// GET /instructor/sessions.php
export interface ClassSession {
  id: number;
  session_date: string;
  class_type: string;
  present_count: number;
  attendees: { first_name: string; last_name: string }[];
}

export interface SessionsData {
  sessions: ClassSession[];
  years: number[];
}

// GET /instructor/belt_tests.php
export interface InstructorBeltTest {
  id: number;
  test_date: string;
  result: string;
  score: number | null;
  fee_paid: boolean;
  belt_awarded: boolean;
  notes: string | null;
  student_id: number;
  student: string;
  kyu_dan: string;
  rank_name: string;
}

export interface InstructorBeltTestsData {
  tests: InstructorBeltTest[];
  years: number[];
  students: { id: number; name: string }[];
  is_admin: boolean;
}

// GET /instructor/student.php
export interface InstructorStudent {
  id: number;
  first_name: string;
  last_name: string;
  student_type: string;
  active: boolean;
  date_of_birth: string | null;
  phone: string | null;
  email: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  street_address: string | null;
  city_state_zip: string | null;
  registration_date: string | null;
  injury_waiver: boolean;
  injury_waiver_date: string | null;
  uniform_size: string | null;
  belt_size: string | null;
  medical_note: string | null;
  username: string | null;
  last_login: string | null;
  user_id: number | null;
}

export interface InstructorStudentProfile {
  student: InstructorStudent;
  can_edit_profile: boolean;
  is_admin: boolean;
  ranks: { rank_id: number; name: string; kyu_dan: string; achieved_date: string | null }[];
  attended_sessions: { session_id: number; session_date: string }[];
  belt_tests: {
    id: number;
    test_date: string;
    result: string;
    score: number | null;
    fee_paid: boolean;
    kyu_dan: string;
    rank_name: string;
  }[];
  payments: {
    payment_date: string;
    payment_type: string;
    payment_method: string;
    amount: number;
  }[];
  notes: { id: number; content: string; created_at: string; username: string | null }[];
  family_tabs: { id: number; name: string; role: string }[];
}

export interface InstructorProfileSaved {
  saved: boolean;
  student: InstructorStudent;
}

// GET /instructor/belt_test_edit.php
export interface BeltTestHistoryRow {
  test_date: string;
  kyu_dan: string;
  rank_name: string;
  result: string;
  score: number | null;
  fee_paid: boolean;
}

export interface BeltTestEditStudent {
  id: number;
  name: string;
  name_lf: string;
  current_rank_label: string;
  next_rank_id: number | null;
  history: BeltTestHistoryRow[];
}

export interface BeltTestEditContext {
  test: {
    id: number;
    student_id: number;
    test_date: string;
    rank_id: number;
    score: number | null;
    notes: string;
    fee_paid: boolean;
  } | null;
  students: BeltTestEditStudent[];
  ranks: { id: number; kyu_dan: string; name: string; rank_order: number }[];
  is_admin: boolean;
}

export interface BeltTestSaved {
  test_id: number;
  duplicate: boolean;
  result: string;
  score: number | null;
}

// Legacy JSON endpoints (portal/api/paypal_create.php / paypal_capture.php) —
// no {ok,data} envelope; errors arrive as {error} with a 4xx/5xx status.
export interface PayPalOrderCreated {
  id?: string;
  error?: string;
}

export interface PayPalCaptureResult {
  success: boolean;
  amount?: number;
  transaction_id?: string | null;
  error?: string;
}
