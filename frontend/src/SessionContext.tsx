// Session + family context — loaded once at app start (me → csrf token,
// family → tab bar), refreshable after mutations that change family state
// (e.g. signing a waiver).

import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { apiGet, ApiError, fetchMe } from './api/client';
import type { Family, Me } from './api/types';

interface Session {
  me: Me;
  family: Family;
  refreshFamily: () => Promise<void>;
}

const SessionContext = createContext<Session | null>(null);

export function useSession(): Session {
  const session = useContext(SessionContext);
  if (!session) throw new Error('useSession outside SessionProvider');
  return session;
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const [me, setMe] = useState<Me | null>(null);
  const [family, setFamily] = useState<Family | null>(null);
  const [error, setError] = useState<string | null>(null);

  const refreshFamily = useCallback(async () => {
    setFamily(await apiGet<Family>('/parent/family.php'));
  }, []);

  useEffect(() => {
    Promise.all([fetchMe(), apiGet<Family>('/parent/family.php')])
      .then(([meData, familyData]) => {
        setMe(meData);
        setFamily(familyData);
      })
      .catch((e: unknown) => {
        // 401 already redirected to login inside the client
        if (!(e instanceof ApiError && e.status === 401)) {
          setError('Could not reach the portal API.');
        }
      });
  }, []);

  if (error) return <div className="alert alert-danger m-4">{error}</div>;
  if (!me || !family) {
    return (
      <div className="text-center p-5">
        <div className="spinner-border text-primary" role="status" aria-label="Loading" />
      </div>
    );
  }

  return (
    <SessionContext.Provider value={{ me, family, refreshFamily }}>
      {children}
    </SessionContext.Provider>
  );
}
