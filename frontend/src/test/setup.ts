// Vitest setup — registers Testing Library's jest-dom matchers
// (toBeInTheDocument, toBeVisible, …) on Vitest's expect, and unmounts
// rendered components between tests (auto-cleanup needs vitest globals,
// which this project doesn't enable).
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

afterEach(cleanup);
