import { useEffect, useState } from 'react';
import { api, unwrap } from './api';

/** GET a path; exposes loading (data === null && !problem), error and reload. */
export function useFetch<T>(path: string) {
  const [data, setData] = useState<T | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [tick, setTick] = useState(0);
  useEffect(() => {
    let live = true;
    setData(null); setProblem(null);
    api<unknown>(path).then((b) => live && setData(unwrap<T>(b))).catch((e) => live && setProblem(e.message));
    return () => { live = false; };
  }, [path, tick]);
  return { data, problem, reload: () => setTick((t) => t + 1) };
}
