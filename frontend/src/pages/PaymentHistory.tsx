// Payment history — React port of parent/payment_history.php (stat cards,
// year filter buttons, merged payments+donations table).

import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { apiGet, ApiError } from '../api/client';
import type { PaymentHistory as PaymentHistoryData } from '../api/types';
import { fmtDate, fmtMonth, money, paymentType } from '../format';
import { PageState, SubPageHeading } from '../components/shared';

export default function PaymentHistory() {
  const { id } = useParams();
  const studentId = Number(id);
  const [year, setYear] = useState<number | null>(null);
  const [data, setData] = useState<PaymentHistoryData | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    setData(null);
    const query = year !== null ? `&year=${year}` : '';
    apiGet<PaymentHistoryData>(`/parent/payments.php?student_id=${studentId}${query}`)
      .then(setData)
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load payments.'));
  }, [studentId, year]);

  if (!data || error) return <PageState error={error} loading />;

  const label = year !== null ? `${year}` : null;

  return (
    <>
      <SubPageHeading
        studentId={studentId}
        title="Payment History"
        name={`${data.student.first_name} ${data.student.last_name}`}
      />

      <div className="row g-3 mb-4">
        <div className="col-sm-4">
          <div className="card border-0 shadow-sm text-center">
            <div className="card-body">
              <div className="display-6 fw-bold text-primary">{data.payments.length}</div>
              <div className="text-muted small">{label ? `${label} Payments` : 'Total Payments'}</div>
            </div>
          </div>
        </div>
        <div className="col-sm-4">
          <div className="card border-0 shadow-sm text-center">
            <div className="card-body">
              <div className="display-6 fw-bold text-success">{money(data.filtered_total)}</div>
              <div className="text-muted small">{label ? `${label} Total` : 'All-Time Total'}</div>
            </div>
          </div>
        </div>
        {label && (
          <div className="col-sm-4">
            <div className="card border-0 shadow-sm text-center">
              <div className="card-body">
                <div className="display-6 fw-bold text-secondary">{money(data.total_paid)}</div>
                <div className="text-muted small">All-Time Total</div>
              </div>
            </div>
          </div>
        )}
      </div>

      <div className="card border-0 shadow-sm">
        <div className="card-header bg-white d-flex justify-content-between align-items-center">
          <span className="fw-semibold">
            {label ? `${label} Payments` : 'All Payments'}
            <span className="text-muted fw-normal small ms-1">({data.payments.length})</span>
          </span>
          {data.years.length > 0 && (
            <div className="d-flex gap-2 align-items-center flex-wrap">
              {data.years.map((yr) => (
                <button
                  key={yr}
                  type="button"
                  className={`btn btn-sm ${year === yr ? 'btn-primary' : 'btn-outline-secondary'}`}
                  onClick={() => setYear(yr)}
                >
                  {yr}
                </button>
              ))}
              {year !== null && (
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setYear(null)}>
                  All
                </button>
              )}
            </div>
          )}
        </div>
        <div className="card-body p-0">
          {data.payments.length === 0 ? (
            <p className="p-3 text-muted">No payments {label ? `in ${label}` : 'on record'} yet.</p>
          ) : (
            <div className="table-responsive">
              <table className="table table-sm table-hover mb-0">
                <thead className="table-light">
                  <tr>
                    <th>#</th><th>Date</th><th>Type</th><th>Month</th><th>Method</th>
                    <th className="text-end">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  {data.payments.map((p, i) => (
                    <tr key={`${p.payment_date}-${i}`}>
                      <td className="text-muted small">{data.payments.length - i}</td>
                      <td>{fmtDate(p.payment_date)}</td>
                      <td>{paymentType(p.payment_type)}</td>
                      <td className="text-muted small">
                        {p.payment_type === 'monthly_tuition' && p.month_covered
                          ? fmtMonth(p.month_covered)
                          : '—'}
                      </td>
                      <td>{p.payment_method.charAt(0).toUpperCase() + p.payment_method.slice(1)}</td>
                      <td className="text-end">{money(p.amount)}</td>
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
