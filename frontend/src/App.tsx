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

// HashRouter so the PHP shell (parent/app.php) serves every route without
// server-side rewrites. SessionProvider bootstraps /me.php (session + CSRF
// token) and the family list before any route renders.
export default function App() {
  return (
    <HashRouter>
      <SessionProvider>
        <Layout>
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
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Layout>
      </SessionProvider>
    </HashRouter>
  );
}
