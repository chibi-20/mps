import type { Payload } from "../types";
export interface SubmitResponse {
  id: string;
  status: "accepted";
  mock: boolean;
}
export interface DocumentApi {
  submitScannedDocument(payload: Payload): Promise<SubmitResponse>;
}
export const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || "";
export const mockMode = import.meta.env.VITE_API_MODE !== "disabled";
export const documentApi: DocumentApi = {
  async submitScannedDocument(payload) {
    if (!mockMode) throw new Error("Production submission is not configured.");
    if (!payload.document.fileName)
      throw new Error("Missing document metadata.");
    await new Promise((resolve) => setTimeout(resolve, 700));
    return { id: crypto.randomUUID(), status: "accepted", mock: true };
  },
};
