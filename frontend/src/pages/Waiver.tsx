// Injury waiver — React port of parent/waiver.php. Signed waivers render as
// a read-only document with the submitted values; unsigned ones present the
// form, validated client-side the same way the server validates (the server
// remains authoritative — its 422 messages surface in the alert).

import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../api/client';
import type { WaiverSign, WaiverSigned, WaiverStatus } from '../api/types';
import { isMinor as dobIsMinor } from '../belt';
import { PageState } from '../components/shared';
import { fmtDate, fmtPhone, personName } from '../format';
import { useSession } from '../SessionContext';

function today(): string {
  const d = new Date();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  return `${d.getFullYear()}-${m}-${String(d.getDate()).padStart(2, '0')}`;
}

function WLabel({ children }: { children: React.ReactNode }) {
  return <span className="w-label">{children}</span>;
}

function WStatic({ value, style }: { value: string; style?: React.CSSProperties }) {
  return <div className="w-static" style={style}>{value}</div>;
}

/** The agreement text itself — verbatim from the PHP page. */
function WaiverDocument() {
  return (
    <>
      <h2>Shotokan Karate Training Program</h2>
      <h3>Waiver of Legal Rights and Indemnification Agreement</h3>

      <p>The undersigned applicant for participation in the Shotokan Karate Training Program taught by Noji Ratzlaff and hosted by Center Stage at 575 South 1200 West, Orem, Utah (<strong><em>Karate Training</em></strong> herein refers collectively to karate training/practice, related physical conditioning, and self-defense instruction/activities or exercises as taught by Noji Ratzlaff and hosted by Center Stage at 575 South 1200 West, Orem, Utah.) agrees, represents, and warrants as follows:</p>

      <p><strong>1.</strong>&nbsp; Undersigned has prepared himself/herself mentally and physically for this Karate Training, is in adequate physical health, and has no material injuries, mental or physical conditions or impairments whatsoever that would prohibit, impair, or make difficult, inadvisable, dangerous, or physically harmful undersigned's participation in the Karate Training.</p>

      <p><strong>2.</strong>&nbsp; Undersigned has fully familiarized himself/herself with the curriculum and is fully aware of the nature of the rigorous physical activities that occur therein, and undersigned requests to participate in such Karate Training activities. Undersigned represents that he/she is capable of participating fully in such activities, and acknowledges that there is a risk of physical injury and possibility of death in participation in such activities. Undersigned acknowledges that he/she must judge his/her own mental and physical capabilities with respect to the Karate Training activities and should inform instructor immediately of any accident or injury or of undersigned's inability or unwillingness to participate fully in any such activity.</p>

      <p><strong>3.</strong>&nbsp; In consideration for the acceptance by Noji Ratzlaff and Center Stage of undersigned as participant in said Karate Training,</p>
      <p className="ms-4">(a) Undersigned fully assumes any and all risk of injury, accident, harm, death, or damage of any nature whatsoever that may accrue to or befall undersigned arising out of attendance at or participation in the Karate Training;</p>
      <p className="ms-4">(b) Undersigned waives any claim, demand, action, or cause of action that undersigned now has or may hereafter acquire against Noji Ratzlaff, Center Stage, or other Karate Training participants and their schools and instructors, arising out of attendance at or participation in the Karate Training. (Noji Ratzlaff, Center Stage, and other Karate Training participants and their schools and instructors are hereafter referred to as <em>said persons</em>.);</p>
      <p className="ms-4">(c) Undersigned releases the same said persons from any liability for such claim, demand, action, or cause of action, if brought by undersigned;</p>
      <p className="ms-4">(d) Undersigned covenants that he/she will not sue or commence any legal action proceedings whatsoever against any of the same said persons over any such claim, demand, action, or cause of action;</p>
      <p className="ms-4">(e) Undersigned agrees to indemnify and hold harmless Noji Ratzlaff, Center Stage, their assigns, family members, relatives, successors, interests, and contractors from any and all claims, demands, actions, liability, loss, expense, and/or attorneys' fees that may arise from or be incurred as a result of:</p>
      <p className="ms-5">(i) injury, damage, or death to undersigned's person or property during this Karate Training;</p>
      <p className="ms-5">(ii) injury, damage, or death to any other Karate Training participant's person or property arising or resulting from the acts or omissions of undersigned.</p>

      <p>Undersigned acknowledges that Noji Ratzlaff, Center Stage, other Karate Training participants, and others may not be insured (wholly or in part) against any claims or actions by undersigned (or others) arising out of undersigned's participation in Karate Training activities, and that Noji Ratzlaff and Center Stage could not and would not accept undersigned as a participant in this Karate Training were it not for the full assumption by undersigned of all risk of injury pertaining thereto and for undersigned's waiver, release, covenant not to sue, and indemnity and other agreements as set forth herein.</p>

      <p><strong>4.</strong>&nbsp; Undersigned acknowledges that it is undersigned's responsibility to provide full medical insurance for any injury that may befall undersigned, and undersigned represents that undersigned has obtained such medical insurance, which will cover 100% of any and all medical expenses, loss, damage, death, and/or disability resulting from any injury to undersigned in or at any Karate Training, or the undersigned will fully assume the risk of failing to procure such insurance.</p>
      <p>Undersigned acknowledges that his/her sole remedy in the event of injury, loss, or damage arising from any Karate Training shall be said medical insurance obtained by undersigned. Undersigned acknowledges that neither Noji Ratzlaff, Center Stage, nor their contractors, provide medical care, and that this Karate Training is or may be held in a vicinity where hospital or other medical services or facilities are not readily available. Undersigned will be responsible for ensuring that undersigned has a means of transportation, if necessary, to convey undersigned to a hospital or medical facility.</p>

      <p><strong>5.</strong>&nbsp; Although Noji Ratzlaff and Center Stage will or may request undersigned to execute other consent forms for other Karate Training programs as Noji Ratzlaff and Center Stage deem necessary, the representations, agreements, covenants, and indemnities herein of undersigned shall apply to all Karate Training programs attended by undersigned.</p>

      <p><strong>6.</strong>&nbsp; Undersigned agrees that the various provisions of this agreement are severable, and the invalidity or inapplicability of any provision hereof in this agreement shall be governed by the laws of the state in which this agreement is fully performed in said state. If, under the laws of said state, consents, waivers, releases, and/or agreements as set forth herein are required, as a condition of their enforceability, to be in a certain form or to contain special language, such special form or language is deemed incorporated by reference herein, and undersigned covenants that he/she would have executed, and will upon request of Noji Ratzlaff and Center Stage execute (with retroactive effect to the date hereof) an agreement pertaining to the subject matter hereof that contains such special form or language.</p>

      <p><strong>7.</strong>&nbsp; This agreement represents the complete embodiment of the understanding and agreements between Noji Ratzlaff, Center Stage, and undersigned, regarding the subject matter except in writing executed by undersigned and an authorized representative of Noji Ratzlaff and Center Stage.</p>

      <p><strong>8.</strong>&nbsp; Undersigned represents that he/she is not a minor or, if a minor, that undersigned has had undersigned's parent or legal guardian sign the parental consent and indemnity agreement of Noji Ratzlaff and Center Stage.</p>

      <p><strong>9. NOJI RATZLAFF AND CENTER STAGE SUGGEST THAT, IF UNDERSIGNED HAS ANY QUESTIONS OR RESERVATIONS ABOUT ANY OF THE FOREGOING, UNDERSIGNED SHOULD NOT EXECUTE THIS AGREEMENT UNTIL AFTER CONSULTING WITH AN ATTORNEY. UNDERSIGNED HAS EITHER CONSULTED AN ATTORNEY REGARDING THE CONTENTS OF THIS AGREEMENT OR DEEMS IT UNNECESSARY TO CONSULT SUCH ATTORNEY.</strong></p>

      <p><strong>10. UNDERSIGNED UNDERSTANDS THAT BY SIGNING THIS AGREEMENT, UNDERSIGNED IS GIVING UP HIS OR HER LEGAL RIGHTS AND LEGAL RIGHTS OF UNDERSIGNED'S HEIRS IN CASE OF INJURY, LOSS, DAMAGE, OR DEATH.</strong></p>

      <p>Undersigned represents that he/she has carefully read each and every one of the provisions hereof, fully understands each provision, and consents to be bound thereby.</p>
      <p>Undersigned acknowledges receipt of a copy of this agreement.</p>

      <hr className="my-4" />
    </>
  );
}

export default function Waiver() {
  const { id } = useParams();
  const studentId = Number(id);
  const { refreshFamily } = useSession();

  const [status, setStatus] = useState<WaiverStatus | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [justSigned, setJustSigned] = useState(false);

  const [form, setForm] = useState<WaiverSign>({
    student_id: studentId,
    print_name: '',
    signature: '',
    signed_date: today(),
    guardian_signature: '',
    guardian_signed_date: '',
    date_of_birth: '',
    cell_phone: '',
    home_phone: '',
    email: '',
    street_address: '',
    city_state_zip: '',
    mailing_address: '',
    mailing_city_state_zip: '',
    i_agree: false,
  });

  useEffect(() => {
    apiGet<WaiverStatus>(`/parent/waiver.php?student_id=${studentId}`)
      .then((s) => {
        setStatus(s);
        setForm((f) => ({
          ...f,
          student_id: studentId,
          print_name: s.prefill.print_name,
          date_of_birth: s.prefill.date_of_birth ?? '',
          cell_phone: s.prefill.cell_phone ?? '',
          email: s.prefill.email ?? '',
          street_address: s.prefill.street_address ?? '',
          city_state_zip: s.prefill.city_state_zip ?? '',
        }));
      })
      .catch((e: unknown) => setLoadError(e instanceof ApiError ? e.message : 'Could not load the waiver.'));
  }, [studentId]);

  if (!status || loadError) return <PageState error={loadError} loading />;

  const set = (key: keyof WaiverSign) => (value: string | boolean) =>
    setForm((f) => ({ ...f, [key]: value }));

  // Live minor check: typed DOB wins, falls back to the record's DOB
  const isMinor = form.date_of_birth ? dobIsMinor(form.date_of_birth) : status.is_minor;
  const signed = status.signed || justSigned;
  const sub = status.submission;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    setFormError(null);
    try {
      await apiPost<WaiverSigned>('/parent/waiver.php', form);
      setJustSigned(true);
      // Re-fetch: the server now has the submission of record, and the waiver
      // flag feeds the family tab bar + dashboard cards.
      const [fresh] = await Promise.all([
        apiGet<WaiverStatus>(`/parent/waiver.php?student_id=${studentId}`),
        refreshFamily(),
      ]);
      setStatus(fresh);
      window.scrollTo(0, 0);
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not submit the waiver.');
      window.scrollTo(0, 0);
    } finally {
      setSubmitting(false);
    }
  }

  const name = personName(`${status.student.first_name} ${status.student.last_name}`);

  return (
    <div className="waiver-wrap">
      {formError && <div className="alert alert-danger mb-3">{formError}</div>}

      {signed && (
        <div className="alert alert-success mb-3">
          This waiver for <strong>{name}</strong>
          {status.signed_date || justSigned ? (
            <> was signed on <strong>{fmtDate(status.signed_date ?? today())}</strong>.</>
          ) : (
            <> has been signed.</>
          )}{' '}
          The waiver is on file — no further action is needed.
        </div>
      )}

      <form onSubmit={(e) => void submit(e)}>
        <div className="card border-0 shadow-sm">
          <div className="card-body px-5 py-4 waiver-doc">
            <WaiverDocument />

            <WLabel>Print your name (undersigned)</WLabel>
            {signed ? (
              <WStatic value={sub?.print_name ?? form.print_name} />
            ) : (
              <input type="text" className="w-input" required
                     value={form.print_name} onChange={(e) => set('print_name')(e.target.value)} />
            )}

            {(!isMinor || signed) && (
              <div className="row g-4 align-items-end mt-1">
                <div className="col">
                  <WLabel>
                    X &nbsp; Student's signature
                    {!signed && (
                      <span style={{ fontSize: '.72rem', color: '#999' }}>
                        {' '}(type full name — constitutes electronic signature)
                      </span>
                    )}
                  </WLabel>
                  {signed ? (
                    <WStatic value={sub?.signature ?? form.signature ?? ''} />
                  ) : (
                    <input type="text" className="w-input w-sig"
                           required={!isMinor}
                           placeholder="Type full name to sign"
                           value={form.signature} onChange={(e) => set('signature')(e.target.value)} />
                  )}
                </div>
                <div className="col-auto" style={{ minWidth: 160 }}>
                  <WLabel>Date</WLabel>
                  {signed ? (
                    <WStatic value={sub?.signed_date ? fmtDate(sub.signed_date) : (status.signed_date ? fmtDate(status.signed_date) : '')} />
                  ) : (
                    <input type="date" className="w-input" required={!isMinor}
                           value={form.signed_date} onChange={(e) => set('signed_date')(e.target.value)} />
                  )}
                </div>
              </div>
            )}

            <div className="row g-4 align-items-end mt-1">
              <div className="col">
                <WLabel>
                  X &nbsp; Signature of parent or guardian
                  {isMinor && !signed ? (
                    <span style={{ fontSize: '.72rem', color: '#999' }}> (required for minors)</span>
                  ) : (
                    ' (if under 21 years of age)'
                  )}
                </WLabel>
                {signed ? (
                  <WStatic value={sub?.guardian_signature ?? ''} />
                ) : (
                  <input type="text" className="w-input w-sig"
                         required={isMinor}
                         placeholder={isMinor ? 'Guardian full name' : 'Guardian full name (if applicable)'}
                         value={form.guardian_signature}
                         onChange={(e) => set('guardian_signature')(e.target.value)} />
                )}
              </div>
              <div className="col-auto" style={{ minWidth: 160 }}>
                <WLabel>Date</WLabel>
                {signed ? (
                  <WStatic value={sub?.guardian_signed_date ? fmtDate(sub.guardian_signed_date) : ''} />
                ) : (
                  <input type="date" className="w-input" required={isMinor}
                         value={form.guardian_signed_date}
                         onChange={(e) => set('guardian_signed_date')(e.target.value)} />
                )}
              </div>
            </div>

            <WLabel>Date of Birth</WLabel>
            {signed ? (
              <WStatic value={sub?.date_of_birth ? fmtDate(sub.date_of_birth) : ''} style={{ maxWidth: 220 }} />
            ) : (
              <input type="date" className="w-input" style={{ maxWidth: 220 }}
                     value={form.date_of_birth} onChange={(e) => set('date_of_birth')(e.target.value)} />
            )}

            <WLabel>Cell Phone Number</WLabel>
            {signed ? (
              <WStatic value={sub?.cell_phone ? fmtPhone(sub.cell_phone) : ''} style={{ maxWidth: 280 }} />
            ) : (
              <input type="tel" className="w-input" style={{ maxWidth: 280 }} required
                     value={form.cell_phone} onChange={(e) => set('cell_phone')(e.target.value)} />
            )}

            <WLabel>Home Phone Number (if different)</WLabel>
            {signed ? (
              <WStatic value={sub?.home_phone ? fmtPhone(sub.home_phone) : ''} style={{ maxWidth: 280 }} />
            ) : (
              <input type="tel" className="w-input" style={{ maxWidth: 280 }}
                     value={form.home_phone} onChange={(e) => set('home_phone')(e.target.value)} />
            )}

            <WLabel>Email Address</WLabel>
            {signed ? (
              <WStatic value={sub?.email ?? ''} />
            ) : (
              <input type="email" className="w-input" required
                     value={form.email} onChange={(e) => set('email')(e.target.value)} />
            )}

            <WLabel>Local Street Address</WLabel>
            {signed ? (
              <WStatic value={sub?.street_address ?? ''} />
            ) : (
              <input type="text" className="w-input" required
                     value={form.street_address} onChange={(e) => set('street_address')(e.target.value)} />
            )}

            <WLabel>City, State, ZIP</WLabel>
            {signed ? (
              <WStatic value={sub?.city_state_zip ?? ''} style={{ maxWidth: 420 }} />
            ) : (
              <input type="text" className="w-input" style={{ maxWidth: 420 }} required
                     value={form.city_state_zip} onChange={(e) => set('city_state_zip')(e.target.value)} />
            )}

            <WLabel>Mailing Address (if different)</WLabel>
            {signed ? (
              <WStatic value={sub?.mailing_address ?? ''} />
            ) : (
              <input type="text" className="w-input"
                     value={form.mailing_address} onChange={(e) => set('mailing_address')(e.target.value)} />
            )}

            <WLabel>City, State, ZIP</WLabel>
            {signed ? (
              <WStatic value={sub?.mailing_city_state_zip ?? ''} style={{ maxWidth: 420 }} />
            ) : (
              <input type="text" className="w-input" style={{ maxWidth: 420 }}
                     value={form.mailing_city_state_zip}
                     onChange={(e) => set('mailing_city_state_zip')(e.target.value)} />
            )}

            {!signed && (
              <>
                <hr className="mt-4 mb-3" />
                <div className="form-check mb-3">
                  <input type="checkbox" className="form-check-input" id="i_agree" required
                         checked={form.i_agree} onChange={(e) => set('i_agree')(e.target.checked)} />
                  <label className="form-check-label" htmlFor="i_agree">
                    I have read and fully understand this agreement and voluntarily agree to be bound by its terms.
                  </label>
                </div>
                <button type="submit" className="btn btn-primary px-4 mb-2" disabled={submitting}>
                  {submitting ? 'Submitting…' : 'Submit Signed Waiver'}
                </button>
              </>
            )}
          </div>
        </div>
      </form>
    </div>
  );
}
