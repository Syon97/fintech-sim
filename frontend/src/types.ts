// ASSUMPTION: these shapes mirror docs/api/alerts.md as I understand it.
// If a field name differs in your API, fix it here (and in api.ts unwrap) only.
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
  explanation: string;        // plain-language sentence from the server
  score: number;              // 0..100
  score_label?: string;       // server wording, e.g. "High risk"
  assignee?: string | null;
  created_at: string;         // ISO, UTC
  can?: Can;
}

export interface TimelineEntry { sentence: string; at: string }

export interface AlertDetail extends AlertSummary {
  score_sentence?: string;
  timeline?: TimelineEntry[];
}
