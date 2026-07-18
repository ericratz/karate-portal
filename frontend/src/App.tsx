import { lazy, Suspense } from 'react';
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import Attendance from './pages/Attendance';
import BeltTests from './pages/BeltTests';
import Dashboard from './pages/Dashboard';
import BeltTestEdit from './pages/instructor/BeltTestEdit';
import BeltTestsAll from './pages/instructor/BeltTestsAll';
import Classes from './pages/instructor/Classes';
import InstructorDashboard from './pages/instructor/InstructorDashboard';
import Roster from './pages/instructor/Roster';
import StudentProfilePage from './pages/instructor/StudentProfilePage';
import TakeAttendance from './pages/instructor/TakeAttendance';
import Pay from './pages/Pay';
import PaymentHistory from './pages/PaymentHistory';
import Waiver from './pages/Waiver';
import { SessionProvider } from './SessionContext';

// Admin routes are code-split into their own chunk (React.lazy + Vite dynamic
// import) so parents/students/instructors never download the admin pages.
const AdminDashboard = lazy(() => import('./pages/admin/AdminDashboard'));
const AdminRoster = lazy(() => import('./pages/admin/AdminRoster'));
const AdminUserProfile = lazy(() => import('./pages/admin/UserProfile'));
const AdminCompareAccount = lazy(() => import('./pages/admin/CompareAccount'));
const AdminResolveLink = lazy(() => import('./pages/admin/ResolveLink'));
const AdminPayments = lazy(() => import('./pages/admin/Payments'));
const AdminEmailStudents = lazy(() => import('./pages/admin/EmailStudents'));
const AdminStudentEdit = lazy(() => import('./pages/admin/StudentEdit'));
const AdminClassNotes = lazy(() => import('./pages/admin/ClassNotes'));
const AdminUsers = lazy(() => import('./pages/admin/Users'));
const AdminExemptions = lazy(() => import('./pages/admin/Exemptions'));
const AdminDonations = lazy(() => import('./pages/admin/Donations'));
const AdminExpenses = lazy(() => import('./pages/admin/Expenses'));
const AdminLogs = lazy(() => import('./pages/admin/Logs'));

const lazyFallback = (
  <div className="text-center p-5">
    <div className="spinner-border text-primary" role="status" aria-label="Loading" />
  </div>
);

// HashRouter so the PHP shells (parent/student/instructor/admin app.php)
// serve every route without server-side rewrites. SessionProvider bootstraps
// /me.php (session + CSRF token) and the family list before any route renders.
export default function App() {
  return (
    <HashRouter>
      <SessionProvider>
        <Layout>
          <Suspense fallback={lazyFallback}>
            <Routes>
              <Route path="/" element={<Dashboard />} />
              <Route path="/student/:id" element={<Dashboard />} />
              <Route path="/payments/:id" element={<PaymentHistory />} />
              <Route path="/attendance/:id" element={<Attendance />} />
              <Route path="/belt-tests/:id" element={<BeltTests />} />
              <Route path="/waiver/:id" element={<Waiver />} />
              <Route path="/pay" element={<Pay />} />
              <Route path="/pay/:id" element={<Pay />} />
              <Route path="/instructor" element={<InstructorDashboard />} />
              <Route path="/instructor/roster" element={<Roster />} />
              <Route path="/instructor/attendance" element={<TakeAttendance />} />
              <Route path="/instructor/classes" element={<Classes />} />
              <Route path="/instructor/belt-tests" element={<BeltTestsAll />} />
              <Route path="/instructor/student/:id" element={<StudentProfilePage />} />
              <Route path="/instructor/belt-test-edit" element={<BeltTestEdit />} />
              <Route path="/admin" element={<AdminDashboard />} />
              <Route path="/admin/roster" element={<AdminRoster />} />
              <Route path="/admin/user/:id" element={<AdminUserProfile />} />
              <Route path="/admin/compare" element={<AdminCompareAccount />} />
              <Route path="/admin/resolve-link" element={<AdminResolveLink />} />
              <Route path="/admin/payments" element={<AdminPayments />} />
              <Route path="/admin/email" element={<AdminEmailStudents />} />
              <Route path="/admin/student-edit" element={<AdminStudentEdit />} />
              <Route path="/admin/notes" element={<AdminClassNotes />} />
              <Route path="/admin/users" element={<AdminUsers />} />
              <Route path="/admin/waivers" element={<AdminExemptions />} />
              <Route path="/admin/donations" element={<AdminDonations />} />
              <Route path="/admin/expenses" element={<AdminExpenses />} />
              <Route path="/admin/logs" element={<AdminLogs />} />
              <Route path="*" element={<Navigate to="/" replace />} />
            </Routes>
          </Suspense>
        </Layout>
      </SessionProvider>
    </HashRouter>
  );
}
