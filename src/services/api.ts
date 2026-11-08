import type { Payload } from "../types";

export interface SubmitResponse {
  id: string;
  status: "accepted";
  mock: boolean;
}

export interface MpsAssessment {
  assessment_id: number;
  title: string;
  total_items: number;
  status: string;
  subject_id: number;
  subject_name: string;
  section_id: number;
  section_name: string;
  answer_key_items: number;
  answer_key_ready: boolean;
}

export interface MpsScan {
  submission_id: string;
  assessment_id: number;
  section_id: number;
  student_id: string;
  answers: ("A" | "B" | "C" | "D" | null)[];
}

export interface DocumentApi {
  submitScannedDocument(payload: Payload): Promise<SubmitResponse>;
}

export const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || "";
export const mockMode = import.meta.env.VITE_API_MODE !== "disabled";

export interface ScannerSession { baseUrl: string; token: string; teacherId: number; teacherDisplay: string; expiresAt: string }

export class MpsApiError extends Error {
  constructor(message: string, public readonly status: number) { super(message); this.name = "MpsApiError"; }
}

function apiUrl(baseUrl: string, path: string) {
  return `${baseUrl.replace(/\/+$/, "")}/api/${path}`;
}

async function request<T>(baseUrl: string, token: string, path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(apiUrl(baseUrl, path), {
    ...init,
    headers: {
      Authorization: `Bearer ${token}`,
      "Content-Type": "application/json",
      ...init?.headers,
    },
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new MpsApiError(body.error || `MPS API request failed (${response.status}).`, response.status);
  return body as T;
}

export async function loginToMps(baseUrl: string, username: string, password: string): Promise<ScannerSession> {
  const response = await fetch(apiUrl(baseUrl, "scanner_login.php"), { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ username, password }) });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) throw new MpsApiError(body.error || `Sign-in failed (${response.status}).`, response.status);
  return { baseUrl: body.api_base_url || baseUrl.replace(/\/+$/, ""), token: body.token, teacherId: body.teacher_id, teacherDisplay: body.teacher_display, expiresAt: body.expires_at };
}

export async function logoutMps(baseUrl: string, token: string): Promise<void> {
  await request<{ success: boolean }>(baseUrl, token, "scanner_logout.php", { method: "POST", body: "{}" });
}

export async function getMpsAssessments(baseUrl: string, token: string) {
  const body = await request<{ assessments: MpsAssessment[] }>(baseUrl, token, "scanner_assessments.php");
  return body.assessments;
}

export async function submitMpsScan(baseUrl: string, token: string, scan: MpsScan) {
  return request<{ success: boolean; duplicate: boolean; score: number; total_items: number }>(
    baseUrl,
    token,
    "scanner_submit.php",
    { method: "POST", body: JSON.stringify(scan) },
  );
}

export const documentApi: DocumentApi = {
  async submitScannedDocument(payload) {
    if (!mockMode) throw new Error("Live submission is only available for answer sheets.");
    if (!payload.document.fileName) throw new Error("Missing document metadata.");
    await new Promise((resolve) => setTimeout(resolve, 700));
    return { id: crypto.randomUUID(), status: "accepted", mock: true };
  },
};
