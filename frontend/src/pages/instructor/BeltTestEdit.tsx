// Belt test editor — React port of instructor/belt_test_edit.php: the JKA
// grading chart. Type-to-filter student picker (.student-btn rows stay in the
// DOM and toggle via inline display — specs click '.student-btn:visible'),
// lower vs regular chart switching by target rank, live subtotal/total/result
// preview, manual-score fallback on scored edits, fee auto-check from same-day
// history, and server-side validation surfaced in the alert. The chart section
// renders hidden (not unmounted) until a student is chosen.

import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { apiGet, apiPost, ApiError } from '../../api/client';
import type { BeltTestEditContext, BeltTestEditStudent, BeltTestSaved } from '../../api/types';
import { ExtIcon, PageState } from '../../components/shared';
import { personName } from '../../format';

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function testPdfUrl(kyuDan: string | undefined): string | null {
  if (!kyuDan) return null;
  const m = /^(\d+)(?:st|nd|rd|th)\s+(Kyu|Dan)$/i.exec(kyuDan);
  if (!m) return null;
  const num = String(parseInt(m[1], 10)).padStart(2, '0');
  const type = m[2].charAt(0).toUpperCase() + m[2].slice(1).toLowerCase();
  return `https://noji.com/karate/testing/Test-${type}-${num}.pdf`;
}

const LOWER_FIELDS = [
  ['l_basics_form', 50],
  ['l_basics_eff', 30],
  ['l_kumite_form', 5],
  ['l_kumite_eff', 15],
] as const;

const REGULAR_FIELDS = [
  ['r_kata_form', 15],
  ['r_kata_eff', 20],
  ['r_basics_form', 15],
  ['r_basics_eff', 20],
  ['r_kumite_form', 10],
  ['r_kumite_eff', 20],
] as const;

type ScoreState = Record<string, string>;

const emptyScores: ScoreState = Object.fromEntries(
  [...LOWER_FIELDS, ...REGULAR_FIELDS].map(([k]) => [k, '']),
);

function num(scores: ScoreState, key: string): number {
  return parseInt(scores[key], 10) || 0;
}

export default function BeltTestEdit() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const testId = Number(searchParams.get('id') ?? 0);
  const refPid = Number(searchParams.get('ref_pid') ?? 0);
  const preStudent = Number(searchParams.get('student_id') ?? 0);
  const savedFlag = searchParams.get('saved') === '1';
  const dupFlag = searchParams.get('dup') === '1';

  const [ctx, setCtx] = useState<BeltTestEditContext | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const [studentId, setStudentId] = useState(0);
  const [pickerQuery, setPickerQuery] = useState('');
  const [testDate, setTestDate] = useState(today());
  const [rankId, setRankId] = useState(0);
  const [scores, setScores] = useState<ScoreState>(emptyScores);
  const [scoreManual, setScoreManual] = useState('');
  const [notes, setNotes] = useState('');
  const [feePaid, setFeePaid] = useState(false);

  useEffect(() => {
    setCtx(null);
    apiGet<BeltTestEditContext>(`/instructor/belt_test_edit.php${testId ? `?id=${testId}` : ''}`)
      .then((data) => {
        setCtx(data);
        if (data.test) {
          setStudentId(data.test.student_id);
          setTestDate(data.test.test_date);
          setRankId(data.test.rank_id);
          setNotes(data.test.notes);
          setFeePaid(data.test.fee_paid);
          setScoreManual(data.test.score !== null ? String(data.test.score) : '');
        } else if (preStudent && data.students.some((s) => s.id === preStudent)) {
          setStudentId(preStudent);
          const info = data.students.find((s) => s.id === preStudent);
          if (info?.next_rank_id) setRankId(info.next_rank_id);
        }
      })
      .catch((e: unknown) => setError(e instanceof ApiError ? e.message : 'Could not load the form.'));
  }, [testId, preStudent]);

  if (!ctx || error) return <PageState error={error} loading />;

  const isEdit = ctx.test !== null;
  const prefilled = isEdit || (preStudent > 0 && studentId === preStudent);
  const info: BeltTestEditStudent | undefined = ctx.students.find((s) => s.id === studentId);
  const rankById = new Map(ctx.ranks.map((r) => [r.id, r]));
  const targetOrder = rankById.get(rankId)?.rank_order ?? 0;
  const isLower = targetOrder > 0 && targetOrder <= 2;
  const chartType = isLower ? 'lower' : 'regular';
  const chartVisible = studentId > 0;

  const q = pickerQuery.toLowerCase().trim();

  // Live totals — mirrors the old recomputeScore()
  const fields = isLower ? LOWER_FIELDS : REGULAR_FIELDS;
  const filled = fields.some(([k]) => num(scores, k) > 0);
  const total = fields.reduce((sum, [k]) => sum + num(scores, k), 0);
  const lBasics = num(scores, 'l_basics_form') + num(scores, 'l_basics_eff');
  const lKumite = num(scores, 'l_kumite_form') + num(scores, 'l_kumite_eff');
  const rKata = num(scores, 'r_kata_form') + num(scores, 'r_kata_eff');
  const rBasics = num(scores, 'r_basics_form') + num(scores, 'r_basics_eff');
  const rKumite = num(scores, 'r_kumite_form') + num(scores, 'r_kumite_eff');

  const pdfUrl = rankId ? testPdfUrl(rankById.get(rankId)?.kyu_dan) : null;

  function selectStudent(s: BeltTestEditStudent) {
    setStudentId(s.id);
    setPickerQuery('');
    if (!isEdit) {
      setTestDate(today());
      if (s.next_rank_id) setRankId(s.next_rank_id);
    }
  }

  function clearStudent() {
    setStudentId(0);
    setPickerQuery('');
  }

  function onDateChange(d: string) {
    setTestDate(d);
    // Auto-check fee paid if the student already has a fee-paid test that day
    if (d && info && info.history.some((h) => h.test_date.slice(0, 10) === d && h.fee_paid)) {
      setFeePaid(true);
    }
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
    try {
      const result = await apiPost<BeltTestSaved>('/instructor/belt_test_edit.php', {
        action: 'save',
        id: testId || undefined,
        ref_pid: refPid || undefined,
        student_id: studentId,
        test_date: testDate,
        rank_id: rankId,
        fee_paid: feePaid,
        notes,
        chart_type: chartType,
        ...Object.fromEntries(fields.map(([k]) => [k, num(scores, k)])),
        score_manual: scoreManual,
      });
      if (refPid) {
        navigate(`/instructor/student/${refPid}`);
      } else if (ctx?.is_admin) {
        // Stay in edit mode with the saved (and possible duplicate) banner
        const next = new URLSearchParams();
        next.set('id', String(result.test_id));
        next.set('saved', '1');
        if (result.duplicate) next.set('dup', '1');
        setSearchParams(next);
      } else {
        // Instructors can't view existing tests — back to the list (old flow)
        navigate('/instructor/belt-tests');
      }
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not save the belt test.');
      window.scrollTo(0, 0);
    } finally {
      setSaving(false);
    }
  }

  async function deleteTest() {
    if (!window.confirm('Delete this belt test record? This cannot be undone.')) return;
    try {
      await apiPost('/instructor/belt_tests.php', { action: 'delete', id: testId });
      if (refPid) navigate(`/instructor/student/${refPid}`);
      else navigate('/instructor/belt-tests');
    } catch (err: unknown) {
      setFormError(err instanceof ApiError ? err.message : 'Could not delete the test.');
    }
  }

  const scoreInput = (name: string, max: number) => (
    <input
      type="number"
      name={name}
      id={name}
      className="form-control form-control-sm chart-score-input"
      min={0}
      max={max}
      placeholder="0"
      value={scores[name]}
      onChange={(e) => setScores((s) => ({ ...s, [name]: e.target.value }))}
    />
  );

  const sectionHeader = (label: string, points: number) => (
    <div className="chart-section-header">
      <span>{label}</span>
      <span className="fw-normal small opacity-75">Possible {points} points</span>
    </div>
  );

  const scoreRow = (title: string, criteria: string, name: string, max: number) => (
    <div className="row g-2 mb-2">
      <div className="col">
        <div className="border rounded p-2 d-flex justify-content-between align-items-start gap-3">
          <div>
            <div className="fw-semibold small">{title}</div>
            <div className="chart-criteria">{criteria}</div>
          </div>
          <div className="d-flex align-items-center gap-1 flex-shrink-0">
            {scoreInput(name, max)}
            <span className="text-muted small">/ {max}</span>
          </div>
        </div>
      </div>
    </div>
  );

  return (
    <>
      <div className="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <h4 className="mb-0">{isEdit ? 'Edit Belt Test' : 'New Belt Test'}</h4>
        <a
          href="https://noji.com/karate/testing/Grading-Guidelines.pdf"
          target="_blank"
          rel="noreferrer"
          className="btn btn-sm ms-auto text-white"
          style={{ backgroundColor: '#0052cc', borderColor: '#0052cc' }}
        >
          Grading Guidelines <ExtIcon size={12} />
        </a>
      </div>

      {savedFlag && <div className="alert alert-success">Saved.</div>}
      {dupFlag && (
        <div className="alert alert-warning">
          Heads up: this student already had a belt test recorded for the same date and rank —
          this may be a duplicate entry.
        </div>
      )}
      {formError && <div className="alert alert-danger">{formError}</div>}

      <form id="mainForm" onSubmit={(e) => void save(e)}>
        <input type="hidden" name="chart_type" id="chartTypeInput" value={chartType} readOnly />

        {/* Student selector */}
        <div className="mb-3">
          <input type="hidden" name="student_id" id="studentSelect" value={studentId || ''} readOnly />
          {prefilled ? (
            <div className="form-control" style={{ background: 'transparent', borderStyle: 'dashed', maxWidth: 420 }}>
              {info ? personName(info.name_lf) : ''}
            </div>
          ) : (
            <>
              <div
                id="studentSelected"
                className={`${studentId ? 'd-flex' : 'd-none'} justify-content-between align-items-center mb-1`}
                style={{ maxWidth: 760 }}
              >
                <span className="fw-semibold" id="studentSelectedName">
                  {info ? personName(info.name_lf) : ''}
                </span>
                <button
                  type="button"
                  id="clearStudentFilterBtn"
                  className="btn btn-link btn-sm p-0 text-muted"
                  onClick={clearStudent}
                >
                  change
                </button>
              </div>
              <input
                type="text"
                id="studentFilter"
                className="form-control"
                placeholder="Type student name…"
                autoComplete="off"
                autoCorrect="off"
                autoCapitalize="off"
                spellCheck={false}
                style={{ maxWidth: 420, display: studentId ? 'none' : '' }}
                value={pickerQuery}
                onChange={(e) => setPickerQuery(e.target.value)}
              />
              <div
                id="studentList"
                className="list-group mt-1"
                style={{
                  maxWidth: 420,
                  ...(ctx.students.length > 10 ? { maxHeight: 260, overflowY: 'auto' as const } : {}),
                  display: studentId ? 'none' : '',
                }}
              >
                {ctx.students.map((s) => {
                  const hay = `${s.name} ${s.name_lf}`.toLowerCase();
                  const show = q.length > 0 && hay.includes(q);
                  return (
                    <button
                      type="button"
                      key={s.id}
                      className="list-group-item list-group-item-action student-btn"
                      data-id={s.id}
                      style={{ display: show ? '' : 'none' }}
                      onClick={() => selectStudent(s)}
                    >
                      {personName(s.name_lf)}
                    </button>
                  );
                })}
              </div>
            </>
          )}
        </div>

        {/* Belt test history panel */}
        <div id="historyPanel" className="mb-4" style={{ display: chartVisible ? '' : 'none', maxWidth: 760 }}>
          <details className="border rounded shadow-sm">
            <summary
              style={{
                cursor: 'pointer', padding: '10px 14px', fontWeight: 600, fontSize: '.9rem',
                listStyle: 'none', display: 'flex', alignItems: 'center', gap: 8,
                background: 'var(--bs-secondary-bg, #f8f9fa)', borderRadius: 'inherit', userSelect: 'none',
              }}
            >
              ▸ Belt Test History
            </summary>
            <div id="historyContent" className="p-3 border-top">
              {!info || info.history.length === 0 ? (
                <span className="text-muted small">No belt tests on record.</span>
              ) : (
                <div className="table-responsive">
                  <table className="table table-sm table-bordered mb-0">
                    <thead className="table-light">
                      <tr><th>Date</th><th>Testing For</th><th>Score</th><th>Result</th></tr>
                    </thead>
                    <tbody>
                      {info.history.map((h, i) => (
                        <tr key={i}>
                          <td>{h.test_date.slice(0, 10)}</td>
                          <td>{h.kyu_dan} — {h.rank_name}</td>
                          <td>{h.score !== null ? `${h.score}%` : '—'}</td>
                          <td>
                            {h.result === 'pass' ? (
                              <span className="badge bg-success">Pass</span>
                            ) : h.result === 'fail' ? (
                              <span className="badge bg-danger">Fail</span>
                            ) : (
                              <span className="badge bg-secondary">Pending</span>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </details>
        </div>

        <div id="selectPrompt" className="text-muted small mb-4" style={{ display: chartVisible ? 'none' : '' }}>
          Select a student above to open the grading chart.
        </div>

        {/* ═══ GRADING CHART ═══ */}
        <div id="chartSection" style={{ display: chartVisible ? '' : 'none' }}>
          <div className="chart-doc shadow-sm mb-3">
            <div className="text-center py-3 px-3" style={{ borderBottom: '2px solid #6f42c1' }}>
              <div className="text-muted small mb-1"
                   style={{ letterSpacing: '.08em', textTransform: 'uppercase', fontSize: '.7rem' }}>
                JKA Shotokan Karate
              </div>
              <h5 id="chartTitle" className="mb-0 fw-bold">
                {isLower
                  ? 'Grading Chart for 10th and 9th Kyu Tests'
                  : 'Grading Chart for 8th Kyu Through 1st Dan Tests'}
              </h5>
              {pdfUrl && (
                <div className="mt-2">
                  <a
                    id="testPdfBtn"
                    href={pdfUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="btn btn-sm text-white"
                    style={{ backgroundColor: '#0052cc', borderColor: '#0052cc' }}
                  >
                    {rankById.get(rankId)?.kyu_dan} Test <ExtIcon size={12} />
                  </a>
                </div>
              )}
            </div>

            <div className="p-3 pb-1" style={{ borderBottom: '1px solid #e0e0e0' }}>
              <div className="row g-2 mb-2">
                <div className="col-md-9">
                  <label className="form-label small fw-semibold mb-1">Student Name</label>
                  <input type="text" id="chartStudentName" className="form-control form-control-sm"
                         readOnly style={{ background: 'transparent', borderStyle: 'dashed' }}
                         value={info?.name ?? ''} />
                </div>
                <div className="col-md-3">
                  <label className="form-label small fw-semibold mb-1">Test Date *</label>
                  <input type="date" name="test_date" id="chartTestDate"
                         className="form-control form-control-sm" required
                         value={testDate} onChange={(e) => onDateChange(e.target.value)} />
                </div>
              </div>
              <div className="row g-2 mb-3">
                <div className="col-md-6">
                  <label className="form-label small fw-semibold mb-1">Current Rank</label>
                  <input type="text" id="chartCurrentRank" className="form-control form-control-sm"
                         readOnly style={{ background: 'transparent', borderStyle: 'dashed' }}
                         value={info?.current_rank_label ?? ''} />
                </div>
                <div className="col-md-6">
                  <label className="form-label small fw-semibold mb-1">Testing For *</label>
                  <select name="rank_id" id="chartRankSelect" className="form-select form-select-sm" required
                          value={rankId || ''} onChange={(e) => setRankId(Number(e.target.value))}>
                    <option value="">— select rank —</option>
                    {ctx.ranks
                      .filter((r) => r.kyu_dan !== '3rd Dan')
                      .map((r) => (
                        <option key={r.id} value={r.id}>{r.kyu_dan} — {r.name}</option>
                      ))}
                  </select>
                </div>
              </div>
            </div>

            {/* Lower chart (10th / 9th Kyu) */}
            <div id="lowerChart" className="p-3" style={{ display: isLower ? '' : 'none' }}>
              {sectionHeader('BASICS', 80)}
              {scoreRow('Form',
                'Correct technique · Well-formed stances · Good posture · Firm heel placement · Definite movements · Focused attention · Technique accuracy · Coverage',
                'l_basics_form', 50)}
              {scoreRow('Effectiveness',
                'Powerful movements · Correct hip movements · Forceful kiai · Good balance',
                'l_basics_eff', 30)}
              <div className="chart-subtotal">
                Basics subtotal: <strong id="l_basics_total">{filled && isLower ? lBasics : '—'}</strong> / 80
              </div>
              {sectionHeader('KUMITE', 20)}
              {scoreRow('Form', 'Proper stance · Technique form · Partner engagement', 'l_kumite_form', 5)}
              {scoreRow('Effectiveness', 'Controlled power · Timing · Composure', 'l_kumite_eff', 15)}
              <div className="chart-subtotal">
                Kumite subtotal: <strong id="l_kumite_total">{filled && isLower ? lKumite : '—'}</strong> / 20
              </div>
            </div>

            {/* Regular chart (8th Kyu – 1st Dan) */}
            <div id="regularChart" className="p-3" style={{ display: isLower ? 'none' : '' }}>
              {sectionHeader('KATA', 35)}
              {scoreRow('Form',
                'Correct kata or technique · Correct kata sequence · Well-formed stances · Good posture · Firm heel placement · Definite movements · Focused attention · Technique accuracy · Technique coverage',
                'r_kata_form', 15)}
              {scoreRow('Effectiveness',
                'Powerful movements · Correct energy generation · Forceful kiai · Proper breathing · Whole body action · Good balance',
                'r_kata_eff', 20)}
              <div className="chart-subtotal">
                Kata subtotal: <strong id="r_kata_total">{filled && !isLower ? rKata : '—'}</strong> / 35
              </div>
              {sectionHeader('BASICS', 35)}
              {scoreRow('Form',
                'Correct technique · Well-formed stances · Good posture · Firm heel placement · Definite movements · Focused attention · Technique accuracy · Coverage',
                'r_basics_form', 15)}
              {scoreRow('Effectiveness',
                'Powerful movements · Correct energy generation · Forceful kiai · Proper breathing · Whole body action · Good balance',
                'r_basics_eff', 20)}
              <div className="chart-subtotal">
                Basics subtotal: <strong id="r_basics_total">{filled && !isLower ? rBasics : '—'}</strong> / 35
              </div>
              {sectionHeader('KUMITE', 30)}
              {scoreRow('Form', 'Proper stance · Technique form · Partner engagement · Control', 'r_kumite_form', 10)}
              {scoreRow('Effectiveness', 'Controlled power · Timing · Composure · Decisiveness', 'r_kumite_eff', 20)}
              <div className="chart-subtotal">
                Kumite subtotal: <strong id="r_kumite_total">{filled && !isLower ? rKumite : '—'}</strong> / 30
              </div>
            </div>

            {/* Total / Result */}
            <div className="px-3 pb-3">
              <div className="chart-total-row">
                <div className="fs-5">
                  Total: <strong id="totalScore">{filled ? total : '—'}</strong>
                  <span className="opacity-50 small fw-normal"> / 100</span>
                </div>
                <div id="resultBadge">
                  {filled && total >= 80 && (
                    <span className="badge bg-success" style={{ fontSize: '.95rem' }}>PASS</span>
                  )}
                  {filled && total >= 60 && total < 80 && (
                    <span className="badge bg-warning text-dark" style={{ fontSize: '.95rem' }}>RETEST</span>
                  )}
                  {filled && total < 60 && (
                    <span className="badge bg-danger" style={{ fontSize: '.95rem' }}>FAIL</span>
                  )}
                </div>
              </div>
              <div className="text-muted small mt-2 fst-italic" id="resultText">
                {filled && total >= 80 && (
                  <><strong>Pass</strong> — eligible to advance to the next rank. Belt will be awarded.</>
                )}
                {filled && total >= 60 && total < 80 &&
                  'Retest (60–79) — student shows potential but needs more practice. Schedule a retest.'}
                {filled && total < 60 &&
                  'Fail (below 60) — student needs significantly more practice before retesting.'}
              </div>

              {isEdit && ctx.test?.score !== null && ctx.test !== null ? (
                <div className="mt-2 small text-muted border rounded p-2">
                  <strong>Recorded score:</strong> {ctx.test.score}% — re-fill the chart above to
                  update, or enter directly:{' '}
                  <input type="number" name="score_manual" id="scoreManual"
                         className="form-control form-control-sm d-inline-block mt-1"
                         style={{ width: 80 }} min={0} max={100}
                         value={scoreManual} onChange={(e) => setScoreManual(e.target.value)} />
                </div>
              ) : (
                <input type="hidden" name="score_manual" value={scoreManual} readOnly />
              )}
            </div>

            {/* Comments */}
            <div className="px-3 pb-3" style={{ borderTop: '1px solid #e0e0e0', paddingTop: 14 }}>
              <label className="form-label small fw-semibold mb-1" htmlFor="chartNotes">Comments</label>
              <textarea name="notes" id="chartNotes" className="form-control form-control-sm" rows={3}
                        value={notes} onChange={(e) => setNotes(e.target.value)} />
            </div>

            {/* Fee + Save */}
            <div className="d-flex justify-content-between align-items-center px-3 py-3"
                 style={{ borderTop: '1px solid #e0e0e0' }}>
              <div className="form-check mb-0">
                <input type="checkbox" className="form-check-input" name="fee_paid" id="feePaid" value="1"
                       checked={feePaid} onChange={(e) => setFeePaid(e.target.checked)} />
                <label className="form-check-label" htmlFor="feePaid">Belt Test Fee Paid</label>
              </div>
              <button type="submit" className="btn btn-primary" disabled={saving}>
                {isEdit ? 'Save Changes' : 'Record Test'}
              </button>
            </div>
          </div>
        </div>
      </form>

      {isEdit && (
        <div className="d-flex justify-content-end mt-2">
          <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => void deleteTest()}>
            Delete
          </button>
        </div>
      )}
    </>
  );
}
