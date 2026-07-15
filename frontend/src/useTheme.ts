// Watches the <html data-bs-theme> attribute (flipped by the navbar toggle)
// so theme-dependent components — the Chart.js axes — can re-render.

import { useEffect, useState } from 'react';

export function useTheme(): 'light' | 'dark' {
  const [theme, setTheme] = useState<'light' | 'dark'>(
    document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light',
  );

  useEffect(() => {
    const observer = new MutationObserver(() => {
      setTheme(document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });
    return () => observer.disconnect();
  }, []);

  return theme;
}
