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
