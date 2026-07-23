// Make a Payment — React port of parent/pay.php: student selector, fee
// checklist (tuition month picker, donation, custom amount), PayPal Buttons,
// receipt, and the per-family-member auto-pay manager. Order create/capture
// go through the legacy JSON endpoints (api/paypal_create.php /
// paypal_capture.php); auto-pay goes through api/v1/parent/subscription.php.

import { Fragment, useEffect, useRef, useState } from 'react';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError, portalPost } from '../api/client';
import type {
  PayContext,
  PayPalCaptureResult,
  PayPalOrderCreated,
  SubscriptionCancelled,
  SubscriptionCreated,
} from '../api/types';
import { PageState } from '../components/shared';
import { money, personName } from '../format';
import { buildItems, computeTotal, emptySelection, itemLabel, namesHave } from '../pay';
import type { PayItem, PaySelection } from '../pay';
import { loadPayPalSdk } from '../paypalSdk';

interface AutopayMsg {
  type: 'success' | 'info' | 'danger';
  text: string;
}

// ?autopay= flags set by the PHP redirect endpoints (subscription return URL
// and the pay.php stub) — same texts the old page showed.
const autopayFlagMsgs: Record<string, AutopayMsg> = {
  success: { type: 'success', text: 'Auto-pay is set up! PayPal will charge monthly tuition automatically.' },
  already: { type: 'info', text: 'That family member already has an active monthly auto-pay set up.' },
  error: { type: 'danger', text: 'Something went wrong setting up auto-pay. Please try again or contact Noji.' },
  no_profile: { type: 'danger', text: 'No student profile found.' },
  cancelled: { type: 'success', text: 'Auto-pay cancelled.' },
};

interface Receipt {
  forLabel: string;
  lines: { label: string; amount: number }[];
  total: number;
  txnId: string;
}

function defaultMonth(ctx: PayContext, studentId: number): string {
  return ctx.tuition_paid_ids.includes(studentId) ? ctx.next_month_value : ctx.current_month_value;
}

export default function Pay() {
  const { id } = useParams();
  const navigate = useNavigate();
  const location = useLocation();

  const [ctx, setCtx] = useState<PayContext | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [selectedId, setSelectedId] = useState(0);
  const [sel, setSel] = useState<PaySelection>(emptySelection);
  const [note, setNote] = useState('');
  const [autopayIds, setAutopayIds] = useState<number[]>([]);
  const [sdkReady, setSdkReady] = useState<boolean | null>(null); // null while loading
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [payError, setPayError] = useState<string | null>(null);
  const [autopayBusy, setAutopayBusy] = useState(false);
  const [autopayMsg, setAutopayMsg] = useState<AutopayMsg | null>(() => {
    const flag = new URLSearchParams(location.search).get('autopay');
    return flag ? (autopayFlagMsgs[flag] ?? null) : null;
  });

  useEffect(() => {
    apiGet<PayContext>('/parent/pay.php')
      .then((data) => {
        if (data.family.length === 0) {
          // No linked student records — nothing to pay for (matches pay.php)
          navigate('/', { replace: true });
          return;
        }
        const preId = Number(id);
        const startId = data.family.some((f) => f.id === preId) ? preId : data.family[0].id;
        setCtx(data);
        setAutopayIds(data.autopay_active_ids);
        setSelectedId(startId);
        setSel({ ...emptySelection, tuitionMonth: defaultMonth(data, startId) });
      })
      .catch((e: unknown) =>
        setError(e instanceof ApiError ? e.message : 'Could not load the payment page.'),
      );
  }, [id, navigate]);

  useEffect(() => {
    if (!ctx) return;
    let cancelled = false;
    loadPayPalSdk(ctx.paypal_client_id).then((paypal) => {
      if (!cancelled) setSdkReady(paypal !== null);
    });
    return () => {
      cancelled = true;
    };
  }, [ctx]);

  const items = ctx ? buildItems(ctx.fees, sel) : [];
  const total = computeTotal(items);
  const active = total > 0 && !receipt;

  // Latest selection for the PayPal callbacks — the Buttons render once per
  // activation, so createOrder must read current state through a ref.
  const snapRef = useRef({ items, total, note, studentId: selectedId });
  snapRef.current = { items, total, note, studentId: selectedId };

  const paypalContainer = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const container = paypalContainer.current;
    const paypal = window.paypal;
    if (!ctx || !container || !active || !sdkReady || !paypal) return;

    let capturedItems: PayItem[] = [];
    let capturedStudentId = 0;

    const buttons = paypal.Buttons({
      style: { layout: 'vertical', shape: 'rect' },

      createOrder: async () => {
        const snap = snapRef.current;
        capturedItems = snap.items;
        capturedStudentId = snap.studentId;
        const d = await portalPost<PayPalOrderCreated>('/api/paypal_create.php', {
          items: snap.items,
          total: snap.total,
          note: snap.note,
          student_id: snap.studentId,
        });
        if (!d.id) throw new Error(d.error ?? 'Could not create the PayPal order');
        return d.id;
      },

      onApprove: async (data) => {
        try {
          const result = await portalPost<PayPalCaptureResult>('/api/paypal_capture.php', {
            orderID: data.orderID,
          });
          if (result.success) {
            const member = ctx.family.find((f) => f.id === capturedStudentId);
            setReceipt({
              forLabel: member ? personName(member.name) : '',
              lines: capturedItems.map((item) => ({
                label: itemLabel(ctx.fees, item),
                amount: item.amount,
              })),
              total: result.amount ?? 0,
              txnId: result.transaction_id ?? '',
            });
            setPayError(null);
          } else {
            setPayError(result.error ?? 'Unknown error');
          }
        } catch {
          setPayError('Could not confirm the payment — contact the instructor before retrying.');
        }
      },

      onError: (err) => {
        setPayError('PayPal encountered an error. Please try again.');
        console.error(err);
      },
    });

    buttons.render(container).catch(() => {});
    return () => {
      buttons.close?.().catch(() => {});
      container.innerHTML = '';
    };
  }, [ctx, active, sdkReady]);

  if (!ctx || error) return <PageState error={error} loading />;

  const feeKeys = Object.keys(ctx.fees);
  const paidMonths = ctx.paid_months_by_student[String(selectedId)] ?? [];
  const tuitionChecked = sel.checkedFees.includes('monthly_tuition');
  const regChecked = sel.checkedFees.includes('registration');
  const donationAmt = parseFloat(sel.donationAmount) || 0;

  const childPaidNames = ctx.family
    .filter((f) => f.id !== ctx.own_id && ctx.tuition_paid_ids.includes(f.id))
    .map((f) => personName(f.name));
  const showFamilyWarning =
    tuitionChecked && ctx.own_id > 0 && selectedId === ctx.own_id && childPaidNames.length > 0;

  const monthAlreadyPaid = tuitionChecked && paidMonths.includes(sel.tuitionMonth);
  const paidMonthLabel =
    ctx.month_options.find((m) => m.value === sel.tuitionMonth)?.label ?? sel.tuitionMonth;

  const childAutopayNames = ctx.family
    .filter((f) => f.id !== ctx.own_id && autopayIds.includes(f.id))
    .map((f) => personName(f.name));

  function changeStudent(sid: number) {
    if (!ctx) return;
    setSelectedId(sid);
    setSel({ ...emptySelection, tuitionMonth: defaultMonth(ctx, sid) });
    setPayError(null);
  }

  function toggleFee(key: string) {
    setSel((s) => ({
      ...s,
      checkedFees: s.checkedFees.includes(key)
        ? s.checkedFees.filter((k) => k !== key)
        : [...s.checkedFees, key],
    }));
  }

  function toggleDonation() {
    setSel((s) =>
      s.donationChecked
        ? { ...s, donationChecked: false, donationAmount: '', donationAnonymous: false }
        : { ...s, donationChecked: true },
    );
  }

  async function setupAutopay(sid: number) {
    setAutopayBusy(true);
    try {
      const d = await apiPost<SubscriptionCreated>('/parent/subscription.php', {
        action: 'create',
        student_id: sid,
      });
      window.location.assign(d.approve_url); // approve on PayPal, return via autopay=success
    } catch (e: unknown) {
      setAutopayMsg({
        type: e instanceof ApiError && e.status === 409 ? 'info' : 'danger',
        text: e instanceof ApiError ? e.message : 'Something went wrong setting up auto-pay.',
      });
      setAutopayBusy(false);
    }
  }

  async function cancelAutopay(sid: number) {
    if (!window.confirm('Cancel this monthly auto-pay? PayPal will stop charging automatically.'))
      return;
    setAutopayBusy(true);
    try {
      await apiPost<SubscriptionCancelled>('/parent/subscription.php', {
        action: 'cancel',
        student_id: sid,
      });
      setAutopayIds((ids) => ids.filter((i) => i !== sid));
      setAutopayMsg({ type: 'success', text: 'Auto-pay cancelled.' });
    } catch (e: unknown) {
      setAutopayMsg({
        type: 'danger',
        text: e instanceof ApiError ? e.message : 'Cancelling failed. Please try again.',
      });
    } finally {
      setAutopayBusy(false);
    }
  }

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4">
        <Link to={`/student/${selectedId}`} className="btn btn-sm btn-outline-secondary">
          ← Dashboard
        </Link>
        <h4 className="mb-0">Make a Payment</h4>
      </div>

      <div className="row g-4 justify-content-center">
        <div className="col-md-8 col-lg-6">
          <div className="card border-0 shadow-sm mb-4">
            <div className="card-header bg-white fw-semibold">Select Payments</div>
            <div className="card-body">
              <div className="mb-4">
                <label className="form-label fw-semibold" htmlFor="studentSelect">
                  Paying for
                </label>
                <select
                  id="studentSelect"
                  className="form-select"
                  value={selectedId}
                  onChange={(e) => changeStudent(Number(e.target.value))}
                >
                  {ctx.family.map((f) => (
                    <option key={f.id} value={f.id}>
                      {personName(f.name)}
                    </option>
                  ))}
                </select>
              </div>

              {showFamilyWarning && (
                <div className="alert alert-info mb-3 small">
                  {namesHave(childPaidNames)} already paid tuition this month. As a parent of a
                  paid child, you do not need to pay tuition.
                </div>
              )}

              <div className="table-responsive">
                <table className="table table-hover mb-3">
                  <tbody>
                    {feeKeys.map((key) => {
                      const checked = sel.checkedFees.includes(key);
                      return (
                        <Fragment key={key}>
                          <tr
                            className={checked ? 'table-primary' : ''}
                            style={{ cursor: 'pointer' }}
                            onClick={(e) => {
                              if ((e.target as HTMLElement).tagName !== 'INPUT') toggleFee(key);
                            }}
                          >
                            <td style={{ width: 36 }}>
                              <input
                                type="checkbox"
                                className="form-check-input"
                                aria-label={ctx.fees[key].label}
                                checked={checked}
                                onChange={() => toggleFee(key)}
                              />
                            </td>
                            <td>{ctx.fees[key].label}</td>
                            <td className="fw-semibold">{money(ctx.fees[key].amount)}</td>
                          </tr>
                          {key === 'monthly_tuition' && tuitionChecked && (
                            <>
                              <tr>
                                <td></td>
                                <td colSpan={2}>
                                  <label
                                    className="form-label small text-muted mb-1"
                                    htmlFor="tuitionMonth"
                                  >
                                    Which month are you paying for?
                                  </label>
                                  <select
                                    id="tuitionMonth"
                                    className="form-select form-select-sm"
                                    style={{ maxWidth: 180 }}
                                    value={sel.tuitionMonth}
                                    onChange={(e) =>
                                      setSel((s) => ({ ...s, tuitionMonth: e.target.value }))
                                    }
                                  >
                                    {ctx.month_options.map((m) => (
                                      <option key={m.value} value={m.value}>
                                        {m.label}
                                      </option>
                                    ))}
                                  </select>
                                </td>
                              </tr>
                              {monthAlreadyPaid && (
                                <tr>
                                  <td></td>
                                  <td colSpan={2}>
                                    <div className="alert alert-info py-2 mb-0 small">
                                      You have already paid tuition for {paidMonthLabel}.
                                    </div>
                                  </td>
                                </tr>
                              )}
                            </>
                          )}
                          {key === 'registration' &&
                            regChecked &&
                            ctx.reg_paid_ids.includes(selectedId) && (
                              <tr>
                                <td></td>
                                <td colSpan={2}>
                                  <div className="alert alert-warning py-2 mb-0 small">
                                    Registration fee is already on file for this student.
                                  </div>
                                </td>
                              </tr>
                            )}
                        </Fragment>
                      );
                    })}

                    <tr
                      className={sel.donationChecked ? 'table-primary' : ''}
                      style={{ cursor: 'pointer' }}
                      onClick={(e) => {
                        if ((e.target as HTMLElement).tagName !== 'INPUT') toggleDonation();
                      }}
                    >
                      <td style={{ width: 36 }}>
                        <input
                          type="checkbox"
                          className="form-check-input"
                          aria-label="Donation"
                          checked={sel.donationChecked}
                          onChange={toggleDonation}
                        />
                      </td>
                      <td>Donation</td>
                      <td className="fw-semibold text-muted">
                        {donationAmt > 0 ? money(donationAmt) : '—'}
                      </td>
                    </tr>
                    {sel.donationChecked && (
                      <tr>
                        <td></td>
                        <td colSpan={2}>
                          <div className="input-group input-group-sm mb-1" style={{ maxWidth: 160 }}>
                            <span className="input-group-text">$</span>
                            <input
                              type="number"
                              className="form-control"
                              placeholder="0.00"
                              step="0.01"
                              min="1"
                              aria-label="Donation amount"
                              value={sel.donationAmount}
                              onChange={(e) =>
                                setSel((s) => ({ ...s, donationAmount: e.target.value }))
                              }
                            />
                          </div>
                          <div className="form-check mt-1">
                            <input
                              type="checkbox"
                              className="form-check-input"
                              id="donationAnonymous"
                              checked={sel.donationAnonymous}
                              onChange={(e) =>
                                setSel((s) => ({ ...s, donationAnonymous: e.target.checked }))
                              }
                            />
                            <label
                              className="form-check-label small text-muted"
                              htmlFor="donationAnonymous"
                            >
                              Donate anonymously (won't appear in your payment history)
                            </label>
                          </div>
                        </td>
                      </tr>
                    )}
                  </tbody>
                </table>
              </div>

              <div className="border rounded p-3 mb-3">
                <div className="form-check mb-2">
                  <input
                    type="checkbox"
                    className="form-check-input"
                    id="customCheck"
                    checked={sel.customChecked}
                    onChange={(e) =>
                      setSel((s) =>
                        e.target.checked
                          ? { ...s, customChecked: true }
                          : { ...s, customChecked: false, customAmount: '', customReason: '' },
                      )
                    }
                  />
                  <label className="form-check-label fw-semibold" htmlFor="customCheck">
                    Custom / Other Amount
                  </label>
                </div>
                {sel.customChecked && (
                  <div className="row g-2">
                    <div className="col-5">
                      <div className="input-group input-group-sm">
                        <span className="input-group-text">$</span>
                        <input
                          type="number"
                          className="form-control"
                          placeholder="0.00"
                          step="0.01"
                          min="0"
                          aria-label="Custom amount"
                          value={sel.customAmount}
                          onChange={(e) => setSel((s) => ({ ...s, customAmount: e.target.value }))}
                        />
                      </div>
                    </div>
                    <div className="col-7">
                      <input
                        type="text"
                        className="form-control form-control-sm"
                        placeholder="Reason for payment"
                        aria-label="Reason for payment"
                        value={sel.customReason}
                        onChange={(e) => setSel((s) => ({ ...s, customReason: e.target.value }))}
                      />
                    </div>
                  </div>
                )}
              </div>

              <div className="mb-3">
                <input
                  type="text"
                  className="form-control form-control-sm"
                  placeholder="Note (optional)"
                  aria-label="Note"
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                />
              </div>

              <div className="d-flex justify-content-between align-items-center border-top pt-3 mb-3">
                <span className="fw-semibold fs-5">Total</span>
                <span className="fw-bold fs-4 text-success">{money(total)}</span>
              </div>

              {active && sdkReady === false && (
                <div className="alert alert-warning mb-0">
                  PayPal checkout could not be loaded. Please disable content blockers and
                  refresh, or contact the instructor to pay another way.
                </div>
              )}
              <div ref={paypalContainer} style={{ display: active ? '' : 'none' }} />
              {!active && !receipt && (
                <div className="text-muted text-center small">Select at least one payment above.</div>
              )}

              {receipt && (
                <div className="alert alert-success mt-3">
                  <strong>Payment successful!</strong>
                  {receipt.forLabel && (
                    <div className="small text-muted mt-1">Payment for: {receipt.forLabel}</div>
                  )}
                  <div className="mt-2 mb-1 small">
                    {receipt.lines.map((line, i) => (
                      <div className="d-flex justify-content-between" key={i}>
                        <span>{line.label}</span>
                        <span>{money(line.amount)}</span>
                      </div>
                    ))}
                  </div>
                  <div className="d-flex justify-content-between fw-semibold border-top pt-1 mt-1">
                    <span>Total</span>
                    <span>{money(receipt.total)}</span>
                  </div>
                  <div className="text-muted small mt-1">
                    Transaction ID: <code>{receipt.txnId}</code>
                  </div>
                  <Link to="/" className="btn btn-sm btn-success mt-2">
                    Back to Dashboard
                  </Link>
                </div>
              )}

              {payError && (
                <div className="alert alert-danger mt-3">
                  <strong>Payment failed:</strong> {payError}
                </div>
              )}
            </div>
          </div>

          <div className="card border-0 shadow-sm mb-4">
            <div className="card-header bg-white fw-semibold">Monthly Auto-Pay</div>
            <div className="card-body">
              {autopayMsg && (
                <div className={`alert alert-${autopayMsg.type} mb-3`}>{autopayMsg.text}</div>
              )}
              <p className="text-muted small mb-3">
                Set up a recurring monthly payment of {money(ctx.monthly_fee)} through PayPal for
                any family member.
              </p>
              {ctx.family.map((f) => (
                <div key={f.id}>
                  {f.id === ctx.own_id &&
                    !autopayIds.includes(ctx.own_id) &&
                    childAutopayNames.length > 0 && (
                      <div className="alert alert-info small mb-2">
                        {namesHave(childAutopayNames)} auto-pay set up. As a parent of a paying
                        child, you can attend for free — you do not need your own auto-pay.
                      </div>
                    )}
                  <div className="d-flex justify-content-between align-items-center border-top py-2">
                    <span className="fw-semibold">{personName(f.name)}</span>
                    {autopayIds.includes(f.id) ? (
                      <span className="d-flex align-items-center gap-2">
                        <span className="text-success small fw-semibold">✓ Active</span>
                        <button
                          type="button"
                          className="btn btn-sm btn-outline-danger"
                          disabled={autopayBusy}
                          onClick={() => void cancelAutopay(f.id)}
                        >
                          Cancel
                        </button>
                      </span>
                    ) : (
                      <button
                        type="button"
                        className="btn btn-sm btn-success"
                        disabled={autopayBusy}
                        onClick={() => void setupAutopay(f.id)}
                      >
                        Set up Auto-Pay
                      </button>
                    )}
                  </div>
                </div>
              ))}
            </div>
          </div>

          <div className="card border-0 shadow-sm">
            <div className="card-header bg-white fw-semibold">Other Payment Options</div>
            <div className="card-body">
              <p className="mb-0">
                <strong>Mail a check</strong> to:
                <br />
                Shotokan Karate and Self-defense
                <br />
                PO Box 1288, Orem, Utah 84059-1288
              </p>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
