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

export interface InstructorRef {
  id: number;
  name: string;
}

export interface AttendanceContext {
  date: string;
  session_exists: boolean;
  class_type: string;
  instructors: InstructorRef[];            // selectable admins + instructors
  selected_instructor_ids: number[];       // who taught (or the default for a new class)
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
  instructors: InstructorRef[];
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

// ── api/v1/admin ─────────────────────────────────────────────────────────

// GET /admin/roster.php — instructor roster shape plus admin-only columns
export interface AdminRosterStudent extends RosterStudent {
  email: string | null;
  phone: string | null;
  reg_paid: boolean;
}

export interface AdminRosterData {
  students: AdminRosterStudent[];
  ranks: string[];
}

// GET /admin/users.php
export interface AdminUser {
  id: number;
  username: string;
  active: boolean;
  last_login: string | null;
  student_id: number | null;
  student_name: string | null;
  role: string;
}

export interface AdminUsersData {
  users: AdminUser[];
  current_user_id: number;
}

// GET /admin/waivers.php (payment exemptions)
export interface AdminExemption {
  id: number;
  student_id: number;
  student_name: string;
  waiver_type: string;
  reason: string | null;
  granted_date: string;
  granted_by_name: string | null;
}

export interface AdminExemptionsData {
  waivers: AdminExemption[];
  students: StudentRef[];
  years: number[];
}

// GET /admin/donations.php
export interface AdminDonation {
  id: number;
  payment_date: string;
  student_id: number | null;
  donor_name: string | null;
  student_name: string | null;
  payment_method: string;
  notes: string | null;
  recorded_by_name: string | null;
  amount: number;
}

export interface AdminDonationsData {
  donations: AdminDonation[];
  total_shown: number;
  years: number[];
  students: StudentRef[];
}

// GET /admin/expenses.php
export interface AdminExpense {
  id: number;
  expense_date: string;
  expense_type: string;
  description: string | null;
  amount: number;
  paid: boolean;
  recorded_by: string | null;
}

export interface AdminExpensesData {
  expenses: AdminExpense[];
  total: number;
  total_paid: number;
  years: number[];
}

// GET /admin/logs.php — one payload per tab
export interface ActivityLogEntry {
  id: number;
  created_at: string;
  username: string | null;
  action: string;
  target_type: string | null;
  target_id: number | null;
  detail: string | null;
  ip_address: string | null;
}

export interface ActivityLogData {
  limit: number;
  entries: ActivityLogEntry[];
  actions: string[];
  users: string[];
}

export interface ErrorLogEntry {
  id: number;
  logged_at: string;
  level: string;
  channel: string;
  message: string;
  user_id: number | null;
  context: Record<string, unknown> | null;
}

export interface ErrorLogData {
  limit: number;
  logs: ErrorLogEntry[];
  levels: string[];
  channels: string[];
}

export interface MailLogEntry {
  id: number;
  sent_at: string;
  to_email: string;
  subject: string;
  type: string | null;
  status: string;
}

export interface MailLogData {
  limit: number;
  mails: MailLogEntry[];
  types: string[];
}

// GET /admin/dashboard.php
export interface AdminAlertRow {
  id: number;
  created_at: string;
  student_id: number | null;
  user_id: number;
  username: string;
  user_name: string;
  student_name: string | null;
}

export interface AdminDashboardData {
  stats: {
    active_students: number;
    inactive_students: number;
    revenue_month: number;
    revenue_ytd: number;
    rent_ytd: number;
  };
  unpaid: { id: number; name: string }[];
  no_waiver: { id: number; name: string }[];
  alerts_linking: AdminAlertRow[];
  alerts_claimed: AdminAlertRow[];
  alerts_new: AdminAlertRow[];
  link_requests: {
    id: number;
    request_type: string;
    notes: string | null;
    created_at: string;
    user_id: number;
    username: string;
    user_name: string;
  }[];
  possible_links: {
    user_id: number;
    username: string;
    user_name: string;
    student_id: number;
    student_name: string;
    email_match: boolean;
  }[];
  attendance_alert: { show: boolean; date: string };
  rent_alert: boolean;
  recent_payments: {
    payment_date: string;
    amount: number;
    payment_type: string;
    name: string;
  }[];
  has_more_payments: boolean;
  chart: {
    labels: string[];
    data: Record<string, number[]>;
  };
}

// GET /admin/user_profile.php
export interface AdminUserProfile {
  user: {
    id: number;
    username: string;
    email: string | null;
    first_name: string | null;
    last_name: string | null;
    date_of_birth: string | null;
    is_admin: boolean;
    active: boolean;
    created_at: string;
    last_login: string | null;
    student_id: number | null;
    student_name: string | null;
    student_type: string | null;
  };
  unlinked: { id: number; first_name: string; last_name: string; student_type: string }[];
  current_user_id: number;
}

// GET /admin/compare_account.php
export interface CompareAccountData {
  user: {
    id: number;
    username: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    date_of_birth: string | null;
    is_admin: boolean;
    active: boolean;
  };
  existing_link: { id: number; name: string } | null;
  link_request: {
    id: number;
    request_type: string;
    notes: string | null;
    created_at: string;
  } | null;
  student: {
    id: number;
    first_name: string;
    last_name: string;
    email: string | null;
    date_of_birth: string | null;
    student_type: string;
    current_rank: string | null;
    last_attended: string | null;
    registration_date: string | null;
    injury_waiver: boolean;
    linked_user: { id: number; username: string } | null;
  } | null;
  students: { id: number; first_name: string; last_name: string; student_type: string }[];
}

// GET /admin/resolve_link.php
export interface ResolveLinkData {
  request: {
    id: number;
    created_at: string;
    username: string;
    user_name: string;
    user_email: string | null;
    user_dob: string | null;
    duplicate: {
      id: number;
      name: string;
      date_of_birth: string | null;
      email: string | null;
      student_type: string | null;
    } | null;
  };
  candidates: {
    id: number;
    first_name: string;
    last_name: string;
    date_of_birth: string | null;
    email: string | null;
    student_type: string;
    rank_name: string | null;
  }[];
}

// GET /admin/payments.php
export interface AdminPayment {
  id: number;
  student_id: number;
  student_name: string;
  payment_date: string;
  payment_type: string;
  payment_method: string;
  amount: number;
  transaction_id: string | null;
  notes: string | null;
  month_covered: string | null;
  payer_name: string | null;
  payer_note: string | null;
  recorded_by_name: string | null;
}

export interface AdminPaymentsData {
  payments: AdminPayment[];
  total_shown: number;
  years: number[];
  students: StudentRef[];
  fees: Record<string, number>;
}

export interface PaymentRecorded {
  recorded: boolean;
  dup_count: number;
}

// GET /admin/email_students.php
export interface EmailRecipient {
  id: number;
  name: string;
  email: string;
  student_type: string;
}

export interface EmailRecipientsData {
  recipients: EmailRecipient[];
}

export interface EmailSendResult {
  sent: number;
  failed: number;
}

// GET /admin/student_edit.php
export interface StudentEditStudent {
  id: number;
  first_name: string;
  last_name: string;
  date_of_birth: string | null;
  phone: string | null;
  email: string | null;
  emergency_contact_name: string | null;
  emergency_contact_phone: string | null;
  street_address: string | null;
  city_state_zip: string | null;
  registration_date: string | null;
  student_type: string;
  medical_note: string | null;
  uniform_size: string | null;
  belt_size: string | null;
  active: boolean;
  active_override: number | null;
  injury_waiver: boolean;
  injury_waiver_date: string | null;
}

export interface RankOption {
  id: number;
  name: string;
  kyu_dan: string;
}

export interface StudentEditData {
  student: StudentEditStudent | null;
  linked_user?: { id: number; username: string; is_admin: boolean } | null;
  attendance?: { session_id: number; session_date: string; present: boolean }[];
  belt_tests?: {
    id: number;
    test_date: string;
    result: string;
    score: number | null;
    fee_paid: boolean;
    kyu_dan: string;
  }[];
  ranks?: {
    sr_id: number;
    rank_id: number;
    name: string;
    kyu_dan: string;
    achieved_date: string | null;
  }[];
  all_ranks: RankOption[];
  notes?: { id: number; content: string; created_at: string; username: string | null }[];
  payment_waivers?: {
    id: number;
    waiver_type: string;
    granted_date: string;
    reason: string | null;
  }[];
  payments?: {
    id: number;
    payment_date: string;
    payment_type: string;
    payment_method: string;
    amount: number;
    is_donation: boolean;
  }[];
  is_guardian_type?: boolean;
  guardian_links?: { link_id: number; student_id: number; name: string }[];
  guardian_candidates?: { id: number; name: string }[];
}

// GET /admin/student_notes.php
export interface ClassNotesData {
  students: {
    id: number;
    first_name: string;
    last_name: string;
    student_type: string;
    active: boolean;
    active_override: boolean;
    note_count: number;
    last_attended: string | null;
  }[];
  class_notes: { id: number; content: string; created_at: string; username: string | null }[];
}

export interface StudentNotesData {
  student: { id: number; name: string };
  notes: { id: number; content: string; created_at: string; username: string | null }[];
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
