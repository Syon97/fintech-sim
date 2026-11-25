export class ApiError extends Error {
  constructor(public status: number, message: string) { super(message); }
}

function cookie(name: string): string | null {
  const m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : null;
}

let csrfReady = false;
async function ensureCsrf(): Promise<void> {
  if (csrfReady && cookie('XSRF-TOKEN')) return;
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
  csrfReady = true;
}

/** Humane wording for failures; the server's own message wins when it sent one. */
export function humaneMessage(status: number, serverMessage?: string): string {
  if (status === 429) return 'Too many attempts. Please wait a minute and try again.';
  if (status === 403) return 'Your role does not allow that action.';
  if (status === 419) return 'Your session expired. Please reload the page and try again.';
  if (status >= 500) return 'Something went wrong on our side. Nothing was changed. Please try again.';
  return serverMessage || 'That did not work. Please check and try again.';
}

export async function api<T>(path: string, init: { method?: string; body?: unknown } = {}): Promise<T> {
  const method = init.method ?? 'GET';
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (method !== 'GET') {
    await ensureCsrf();
    headers['Content-Type'] = 'application/json';
    const token = cookie('XSRF-TOKEN');
    if (token) headers['X-XSRF-TOKEN'] = token;
  }
  let res: Response;
  try {
    res = await fetch('/api' + path, {
      method, headers, credentials: 'same-origin',
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });
  } catch {
    throw new ApiError(0, 'Could not reach the server. Check your connection and try again.');
  }
  const text = await res.text();
  const json = text ? safeParse(text) : null;
  if (!res.ok) throw new ApiError(res.status, humaneMessage(res.status, json?.message));
  return json as T;
}

function safeParse(text: string): any {
  try { return JSON.parse(text); } catch { return null; }
}

/** Laravel resources often wrap in {data: ...}; accept both. */
export function unwrap<T>(body: any): T {
  return body && typeof body === 'object' && 'data' in body ? body.data : body;
}
