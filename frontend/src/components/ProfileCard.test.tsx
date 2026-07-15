// ProfileCard drives the only mutation on the dashboard — view/edit toggle,
// save through POST /parent/profile.php, server-side validation surfacing.

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetCsrfTokenForTests } from '../api/client';
import type { StudentProfile } from '../api/types';
import ProfileCard from './ProfileCard';

const emily: StudentProfile = {
  id: 4,
  first_name: 'Emily',
  last_name: 'Wilson',
  date_of_birth: null,
  phone: '8015550104',
  email: 'ewilson@email.com',
  emergency_contact_name: null,
  emergency_contact_phone: null,
  street_address: null,
  city_state_zip: null,
  uniform_size: null,
  belt_size: null,
  medical_note: null,
  registration_date: '2025-01-04',
  student_type: 'student',
  injury_waiver: false,
  injury_waiver_date: null,
};

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

const meEnvelope = {
  ok: true,
  data: { user_id: 6, username: 'test', role: 'parent', csrf_token: 'tok' },
};

function renderCard(onSaved = vi.fn()) {
  render(
    <MemoryRouter>
      <ProfileCard student={emily} onSaved={onSaved} />
    </MemoryRouter>,
  );
  return onSaved;
}

describe('ProfileCard', () => {
  const fetchMock = vi.fn();

  beforeEach(() => {
    fetchMock.mockReset();
    resetCsrfTokenForTests();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('renders view mode with formatted values', () => {
    renderCard();
    expect(screen.getByText('Profile Info')).toBeInTheDocument();
    expect(screen.getByText('801-555-0104')).toBeInTheDocument(); // fmtPhone
    expect(screen.getByText('04 Jan 2025')).toBeInTheDocument();  // fmtDate
    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
    expect(document.querySelector('#profile-edit')).not.toBeInTheDocument();
  });

  it('Edit toggles to the form; Cancel restores view mode without saving', async () => {
    const user = userEvent.setup();
    renderCard();

    await user.click(screen.getByRole('button', { name: 'Edit' }));
    expect(document.querySelector('#profile-edit')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();

    await user.clear(screen.getByLabelText('First Name *'));
    await user.type(screen.getByLabelText('First Name *'), 'SHOULD_NOT_SAVE');
    await user.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(document.querySelector('#profile-edit')).not.toBeInTheDocument();
    expect(screen.queryByText(/SHOULD_NOT_SAVE/i)).not.toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('Save posts the form and swaps in the server copy', async () => {
    const user = userEvent.setup();
    const onSaved = renderCard();

    fetchMock
      .mockResolvedValueOnce(jsonResponse(meEnvelope))
      .mockResolvedValueOnce(
        jsonResponse({ ok: true, data: { saved: true, student: { ...emily, first_name: 'Emilia' } } }),
      );

    await user.click(screen.getByRole('button', { name: 'Edit' }));
    await user.clear(screen.getByLabelText('First Name *'));
    await user.type(screen.getByLabelText('First Name *'), 'Emilia');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText('Profile saved.')).toBeInTheDocument();
    expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ first_name: 'Emilia' }));

    const postCall = fetchMock.mock.calls[1];
    expect(postCall[0]).toBe('/karate/portal/api/v1/parent/profile.php');
    const sent = JSON.parse((postCall[1] as RequestInit).body as string) as Record<string, unknown>;
    expect(sent.student_id).toBe(4);
    expect(sent.first_name).toBe('Emilia');
  });

  it('surfaces the server 422 message inside the card and stays in edit mode', async () => {
    const user = userEvent.setup();
    renderCard();

    fetchMock
      .mockResolvedValueOnce(jsonResponse(meEnvelope))
      .mockResolvedValueOnce(
        jsonResponse({ ok: false, error: 'First and last name are required.' }, 422),
      );

    await user.click(screen.getByRole('button', { name: 'Edit' }));
    await user.clear(screen.getByLabelText('First Name *'));
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText('First and last name are required.')).toBeInTheDocument();
    expect(document.querySelector('#profile-edit')).toBeInTheDocument(); // still editing
  });
});
