import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import Attendance from './pages/Attendance';
import BeltTests from './pages/BeltTests';
import Dashboard from './pages/Dashboard';
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
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Layout>
      </SessionProvider>
    </HashRouter>
  );
}
