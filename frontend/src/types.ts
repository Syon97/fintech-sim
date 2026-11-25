// Shapes taken from a real GET /api/alerts/{id} response (Nov 2026).
// If the API changes, this is the one place to fix.
export interface Can { start?: boolean; release?: boolean; close?: boolean; reassign?: boolean }

export interface Me {
  username: string;
  name?: string;
  role: 'analyst' | 'supervisor' | 'rule_admin';
  permissions: string[];
}

export interface AlertSummary {
  id: number;
  status: 'open' | 'investigating' | 'closed';
  severity: string;
  risk_score: number;          // 0..100
  account_number: string;
  explanation: string;         // plain-language sentence from the server
  occurred_at: string;         // ISO, when the activity happened
  assignee: string | null;
  disposition: string | null;
  closed_at: string | null;
  can?: Can;
}

export interface Evidence {
  transaction_id: string;
  direction: 'C' | 'D';        // C = money in, D = money out
  amount_sen: number;
  amount_display: string;      // "RM 9.45", already formatted by the server
  occurred_at: string;
}

export interface TimelineEntry { at: string; actor: unknown; sentence: string; note: string | null }

export interface AlertDetail extends AlertSummary {
  rule: { code: string; name: string; version: number; rationale: string };
  score: { total: number; sentences: string[] };
  evidence: Evidence[];
  group: { id: number; alert_count: number; unresolved: number; max_score: number } | null;
  timeline: TimelineEntry[];
}

// Must match AlertWorkflow::DISPOSITIONS in the backend. The server rejects anything else.
export const DISPOSITIONS: { value: string; label: string }[] = [
  { value: 'false_positive', label: 'Nothing wrong: a false alarm' },
  { value: 'confirmed_suspicious', label: 'Confirmed suspicious' },
];

// ---- Cases (shapes from real responses, Nov 2026) ----
export interface Person { username: string; name: string }

export interface Group {
  id: number;
  account_number: string;
  alert_count: number;
  unresolved: number;
  max_score: number;
  first_alert_at: string;
  last_alert_at: string;
  // Shape when a case exists has not been captured yet; read defensively.
  case: { id?: number; status?: string } | null;
}

export interface CaseSummary {
  id: number;
  group_id: number;
  status: 'open' | 'closed';
  outcome: string | null;
  outcome_label: string | null;
  opened_at: string;
  closed_at: string | null;
  owner: Person | null;
  account: { number: string; status: string };
  alerts: { total: number; unresolved: number; max_score: number };
}

export interface CaseAlert {
  id: number; status: string; severity: string; risk_score: number;
  explanation: string; rule_name: string; disposition: string | null;
  occurred_at: string; assignee: string | null;
}

export interface CaseDetail {
  id: number;
  status: 'open' | 'closed';
  outcome: string | null;
  outcome_label: string | null;
  closing_note: string | null;
  opened_at: string;
  closed_at: string | null;
  owner: Person | null;
  opened_by: Person | null;
  account: { number: string; status: string };
  group: { id: number; alert_count: number; max_score: number; first_alert_at: string; last_alert_at: string };
  alerts: CaseAlert[];
  timeline: { at: string; actor: Person | null; sentence: string; note: string | null }[];
  can: { add_note: boolean; assign: boolean; freeze: boolean; unfreeze: boolean; close: boolean };
  close_blockers: string[];
  must_escalate: boolean;
}

// Must match CaseService::OUTCOMES in the backend.
export const OUTCOMES: { value: string; label: string }[] = [
  { value: 'no_further_action', label: 'No further action needed' },
  { value: 'escalated_to_compliance', label: 'Escalate to compliance' },
];