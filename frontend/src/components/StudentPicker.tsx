// Type-to-filter student picker — React port of the picker markup the admin
// waivers/donations PHP pages shared. Keeps the legacy DOM contract the
// Playwright specs address: `#{idBase}Filter` input, `#{idBase}List` with
// `.{btnClass}` option buttons that hide via inline display until the query
// matches, the selected-name row with a clear button, and (for required
// pickers) setCustomValidity/reportValidity on the visible filter input.

import { forwardRef, useImperativeHandle, useRef, useState } from 'react';
import { personName } from '../format';
import type { StudentRef } from '../api/types';

export interface StudentPickerHandle {
  /** Show the browser's validation bubble on the filter input. */
  reportMissing: (message: string) => void;
}

interface StudentPickerProps {
  students: StudentRef[];
  idBase: string; // e.g. "grantStudent" → #grantStudentFilter / #grantStudentList
  btnClass: string; // e.g. "grant-stu-btn"
  selected: { id: number; label: string } | null;
  onSelect: (id: number, label: string) => void;
  onClear: () => void;
  placeholder: string;
  clearLabel?: string;
  small?: boolean;
  required?: boolean;
  /** Overlay dropdown (position:absolute) instead of an inline list. */
  overlay?: boolean;
  /** name= for the filter input, when the typed text itself posts (donor_name). */
  inputName?: string;
  onInputChange?: (value: string) => void;
}

const StudentPicker = forwardRef<StudentPickerHandle, StudentPickerProps>(function StudentPicker(
  {
    students,
    idBase,
    btnClass,
    selected,
    onSelect,
    onClear,
    placeholder,
    clearLabel = 'change',
    small = false,
    required = false,
    overlay = true,
    inputName,
    onInputChange,
  },
  ref,
) {
  const [query, setQuery] = useState('');
  const inputRef = useRef<HTMLInputElement>(null);

  useImperativeHandle(ref, () => ({
    reportMissing(message: string) {
      const el = inputRef.current;
      if (!el) return;
      el.setCustomValidity(message);
      el.reportValidity();
    },
  }));

  const q = query.toLowerCase().trim();
  const matches = (s: StudentRef) =>
    q.length > 0 &&
    `${s.first_name} ${s.last_name} ${s.last_name} ${s.first_name}`.toLowerCase().includes(q);
  const anyVisible = students.some(matches);

  const inputCls = `form-control${small ? ' form-control-sm' : ''}`;

  return (
    <>
      <input type="hidden" name={`${idBase}_id`} id={`${idBase}Id`} value={selected?.id ?? ''} readOnly />
      <div
        id={`${idBase}Selected`}
        className={`${selected ? 'd-flex' : 'd-none'} justify-content-between align-items-center mb-1`}
      >
        <span className={`fw-semibold${small ? ' small' : ''}`} id={`${idBase}Name`}>
          {selected ? personName(selected.label) : ''}
        </span>
        <button
          type="button"
          id={`clear${idBase.charAt(0).toUpperCase()}${idBase.slice(1)}Btn`}
          className="btn btn-link btn-sm p-0 text-muted"
          onClick={() => {
            setQuery('');
            inputRef.current?.setCustomValidity('');
            onClear();
          }}
        >
          {clearLabel}
        </button>
      </div>
      <div className={overlay ? 'stu-filter-wrap' : undefined}>
        <input
          ref={inputRef}
          type="text"
          id={`${idBase}Filter`}
          name={inputName}
          className={inputCls}
          placeholder={placeholder}
          autoComplete="off"
          autoCorrect="off"
          autoCapitalize="off"
          spellCheck={false}
          required={required && !selected}
          style={{ display: selected ? 'none' : '' }}
          value={query}
          onChange={(e) => {
            e.target.setCustomValidity('');
            setQuery(e.target.value);
            onInputChange?.(e.target.value);
          }}
        />
        <div
          id={`${idBase}List`}
          className={`list-group mt-1${overlay ? ' stu-dropdown' : ''}`}
          style={{
            display: anyVisible ? '' : 'none',
            ...(overlay ? {} : { maxHeight: 200, overflowY: 'auto' as const }),
          }}
        >
          {students.map((s) => {
            const label = `${s.first_name} ${s.last_name}`;
            return (
              <button
                key={s.id}
                type="button"
                className={`list-group-item list-group-item-action ${btnClass}`}
                data-id={s.id}
                style={{ display: matches(s) ? '' : 'none' }}
                onClick={() => {
                  setQuery('');
                  inputRef.current?.setCustomValidity('');
                  onSelect(s.id, label);
                  onInputChange?.('');
                }}
              >
                {personName(label)}
              </button>
            );
          })}
        </div>
      </div>
    </>
  );
});

export default StudentPicker;
