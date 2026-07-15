// Attendance history — React port of parent/attendance.php.

import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiGet, ApiError } from '../api/client';
import type { AttendanceHistory } from '../api/types';
import { fmtDateLong } from '../format';
import { PageState, SubPageHeading } from '../components/shared';

export default function Attendance() {
  const { id } = useParams();
  const studentId = Number(id);
  const [data, setData] = useState<AttendanceHistory | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    apiGet<AttendanceHistory>(`/parent/attendance.php?student_id=${studentId}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load attendance.'));
  }, [studentId]);

  if (!data || error) return <PageState error={error} loading />;

  return (
    <>
      <SubPageHeading
        studentId={studentId}
        title="Attendance History"
        name={`${data.student.first_name} ${data.student.last_name}`}
      />

      <div className="row g-3 mb-4">
        <div className="col-sm-4">
          <div className="card border-0 shadow-sm text-center">
            <div className="card-body">
              <div className="display-6 fw-bold text-primary">{data.total_attended}</div>
              <div className="text-muted small">Classes Attended</div>
            </div>
          </div>
        </div>
      </div>

      <div className="card border-0 shadow-sm" style={{ maxWidth: 400 }}>
        <div className="card-header bg-white fw-semibold">
          All Attended Dates ({data.dates.length})
        </div>
        <div className="card-body p-0">
          {data.dates.length === 0 ? (
            <p className="p-3 text-muted">No attendance on record yet.</p>
          ) : (
            <div className="table-responsive">
              <table className="table table-sm table-hover mb-0">
                <thead className="table-light">
                  <tr><th>#</th><th>Date</th></tr>
                </thead>
                <tbody>
                  {data.dates.map((date, i) => (
                    <tr key={`${date}-${i}`}>
                      <td className="text-muted small">{data.dates.length - i}</td>
                      <td>{fmtDateLong(date)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </div>
    </>
  );
}
