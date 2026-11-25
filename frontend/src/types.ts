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

export interface TimelineEntry { at: string; actor: string | null; sentence: string; note: string | null }

export interface AlertDetail extends AlertSummary {
  rule: { code: string; name: string; version: number; rationale: string };
  score: { total: number; sentences: string[] };
  evidence: Evidence[];
  group: { id: number; alert_count: number; unresolved: number; max_score: number } | null;
  timeline: TimelineEntry[];
}

// TODO(verify): the allowed dispositions live in the backend (AlertWorkflow).
// Replace these values with the real ones; the server answers 422 for unknown values.
export const DISPOSITIONS: { value: string; label: string }[] = [
  { value: 'false_positive', label: 'Nothing wrong: a false alarm' },
  { value: 'suspicious', label: 'Genuinely suspicious' },
  { value: 'escalated', label: 'Needs someone senior to decide' },
];