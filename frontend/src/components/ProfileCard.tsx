// Profile Info card with inline view/edit toggle — the React version of the
// htmx card on parent/index.php. Saves through POST /parent/profile.php and
// swaps in the server's authoritative copy of the record.

import { useState } from 'react';
import type { FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { apiPost, ApiError } from '../api/client';
import type { ProfileSaved, ProfileUpdate, StudentProfile } from '../api/types';
import { fmtDate, fmtPhone, personName } from '../format';

const UNIFORM_SIZES = ['000', '00', '0', '1', '2', '3', '4', '5', '6', '7', '8'];
const BELT_SIZES = ['2', '3', '4', '5', '6', '7', '8'];

const TYPE_BADGES: Record<string, { cls: string; tip?: string }> = {
  student: { cls: 'bg-primary', tip: 'Registration fee paid' },
  parent: { cls: 'bg-info text-dark', tip: 'Family account' },
  guest: { cls: 'bg-secondary', tip: 'Non-paying participant (registration fee not yet paid)' },
};

function emptyForm(s: StudentProfile): ProfileUpdate {
  return {
    student_id: s.id,
    first_name: s.first_name,
    last_name: s.last_name,
    date_of_birth: s.date_of_birth ?? '',
    phone: s.phone ?? '',
    email: s.email,
    emergency_contact_name: s.emergency_contact_name ?? '',
    emergency_contact_phone: s.emergency_contact_phone ?? '',
    street_address: s.street_address ?? '',
    city_state_zip: s.city_state_zip ?? '',
    uniform_size: s.uniform_size ?? '',
    belt_size: s.belt_size ?? '',
    medical_note: s.medical_note ?? '',
  };
}

function ViewRow({ label, children, last }: { label: string; children: React.ReactNode; last?: boolean }) {
  return (
    <div className={`d-flex py-1 ${last ? '' : 'border-bottom'}`}>
      <div className="text-muted small" style={{ minWidth: 160 }}>{label}</div>
      <div>{children}</div>
    </div>
  );
}

export default function ProfileCard({
  student,
  onSaved,
}: {
  student: StudentProfile;
  onSaved: (s: StudentProfile) => void;
}) {
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<ProfileUpdate>(() => emptyForm(student));
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const set = (key: keyof ProfileUpdate) => (value: string) =>
    setForm((f) => ({ ...f, [key]: value }));

  function startEdit() {
    setForm(emptyForm(student));
    setSaved(false);
    setError(null);
    setEditing(true);
  }

  async function save(e?: FormEvent) {
    e?.preventDefault();
    setSaving(true);
    setError(null);
    try {
      const result = await apiPost<ProfileSaved>('/parent/profile.php', form);
      onSaved(result.student);
      setEditing(false);
      setSaved(true);
    } catch (err: unknown) {
      setError(err instanceof ApiError ? err.message : 'Could not save the profile.');
    } finally {
      setSaving(false);
    }
  }

  const badge = TYPE_BADGES[student.student_type] ?? { cls: 'bg-secondary' };
  const address = [student.street_address, student.city_state_zip].filter(Boolean);

  return (
    <div id="profile-card" className="card border-0 shadow-sm">
      <div className="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Profile Info</span>
        <div className="d-flex gap-2">
          {editing && (
            <button
              type="button"
              id="profileCancelBtn"
              className="btn btn-sm btn-secondary"
              onClick={() => { setEditing(false); setError(null); }}
            >
              Cancel
            </button>
          )}
          <button
            type="button"
            id="profileEditBtn"
            className={`btn btn-sm ${editing ? 'btn-warning' : 'btn-success'}`}
            disabled={saving}
            onClick={() => (editing ? void save() : startEdit())}
          >
            {editing ? (saving ? 'Saving…' : 'Save') : 'Edit'}
          </button>
        </div>
      </div>
      <div className="card-body py-2 px-3">
        {error && <div className="alert alert-danger py-2 mb-3">{error}</div>}
        {saved && !editing && <div className="alert alert-success py-2 mb-3">Profile saved.</div>}

        {!editing ? (
          <div id="profile-view">
            <ViewRow label="First Name">{personName(student.first_name) || '—'}</ViewRow>
            <ViewRow label="Last Name">{personName(student.last_name) || '—'}</ViewRow>
            <ViewRow label="Date of Birth">{student.date_of_birth ? fmtDate(student.date_of_birth) : '—'}</ViewRow>
            <ViewRow label="Phone">{student.phone ? fmtPhone(student.phone) : '—'}</ViewRow>
            <ViewRow label="Email">{student.email || '—'}</ViewRow>
            <ViewRow label="Emergency Contact">{student.emergency_contact_name || '—'}</ViewRow>
            <ViewRow label="Emergency Phone">
              {student.emergency_contact_phone ? fmtPhone(student.emergency_contact_phone) : '—'}
            </ViewRow>
            <ViewRow label="Address">
              {address.length > 0
                ? address.map((part, i) => <div key={i}>{part}</div>)
                : '—'}
            </ViewRow>
            <ViewRow label="Member Since">
              {student.registration_date ? fmtDate(student.registration_date) : '—'}
            </ViewRow>
            <ViewRow label="Account Type">
              <span className={`badge ${badge.cls}`} title={badge.tip}>
                {student.student_type.charAt(0).toUpperCase() + student.student_type.slice(1)}
              </span>
            </ViewRow>
            <ViewRow label="Waiver">
              {student.injury_waiver ? (
                <>
                  <span className="text-success">✓</span>
                  {student.injury_waiver_date ? ` ${fmtDate(student.injury_waiver_date)}` : ''}
                  <Link to={`/waiver/${student.id}`} className="btn btn-sm btn-outline-secondary ms-2">View</Link>
                </>
              ) : (
                '—'
              )}
            </ViewRow>
            <ViewRow label="Uniform Size">{student.uniform_size || '—'}</ViewRow>
            <ViewRow label="Belt Size">{student.belt_size || '—'}</ViewRow>
            <ViewRow label="Medical Note" last>
              {student.medical_note
                ? student.medical_note.split('\n').map((line, i) => <div key={i}>{line}</div>)
                : '—'}
            </ViewRow>
          </div>
        ) : (
          <div id="profile-edit">
          <form id="profile-form" className="row g-3 py-2" onSubmit={(e) => void save(e)}>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-first">First Name *</label>
              <input id="pf-first" name="first_name" type="text" className="form-control" required
                     value={form.first_name} onChange={(e) => set('first_name')(e.target.value)} />
            </div>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-last">Last Name *</label>
              <input id="pf-last" name="last_name" type="text" className="form-control" required
                     value={form.last_name} onChange={(e) => set('last_name')(e.target.value)} />
            </div>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-dob">Date of Birth</label>
              <input id="pf-dob" name="date_of_birth" type="date" className="form-control"
                     value={form.date_of_birth} onChange={(e) => set('date_of_birth')(e.target.value)} />
            </div>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-phone">Phone</label>
              <input id="pf-phone" name="phone" type="tel" className="form-control"
                     value={form.phone} onChange={(e) => set('phone')(e.target.value)} />
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="pf-email">Email</label>
              <input id="pf-email" name="email" type="email" className="form-control"
                     value={form.email} onChange={(e) => set('email')(e.target.value)} />
            </div>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-ecname">Emergency Contact</label>
              <input id="pf-ecname" name="ec_name" type="text" className="form-control"
                     value={form.emergency_contact_name}
                     onChange={(e) => set('emergency_contact_name')(e.target.value)} />
            </div>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-ecphone">Emergency Phone</label>
              <input id="pf-ecphone" name="ec_phone" type="tel" className="form-control"
                     value={form.emergency_contact_phone}
                     onChange={(e) => set('emergency_contact_phone')(e.target.value)} />
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="pf-street">Street Address</label>
              <input id="pf-street" name="street_address" type="text" className="form-control"
                     value={form.street_address} onChange={(e) => set('street_address')(e.target.value)} />
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="pf-csz">City, State, ZIP</label>
              <input id="pf-csz" name="city_state_zip" type="text" className="form-control"
                     value={form.city_state_zip} onChange={(e) => set('city_state_zip')(e.target.value)} />
            </div>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-uniform">Uniform Size</label>
              <select id="pf-uniform" name="uniform_size" className="form-select"
                      value={form.uniform_size} onChange={(e) => set('uniform_size')(e.target.value)}>
                <option value="">— not set —</option>
                {UNIFORM_SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
            <div className="col-6">
              <label className="form-label" htmlFor="pf-belt">Belt Size</label>
              <select id="pf-belt" name="belt_size" className="form-select"
                      value={form.belt_size} onChange={(e) => set('belt_size')(e.target.value)}>
                <option value="">— not set —</option>
                {BELT_SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="pf-medical">Medical Note</label>
              <textarea id="pf-medical" name="medical_note" className="form-control" rows={2}
                        value={form.medical_note} onChange={(e) => set('medical_note')(e.target.value)} />
            </div>
            {/* Hidden submit so Enter in a field saves, like a normal form */}
            <button type="submit" className="d-none" aria-hidden="true" />
          </form>
          </div>
        )}
      </div>
    </div>
  );
}
