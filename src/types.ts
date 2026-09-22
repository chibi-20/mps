export interface Field {
  status?:
    "marked" | "blank" | "multiple" | "uncertain" | "unreviewed" | "reviewed";
  key: string;
  label: string;
  value: string;
  confidence: number;
  page: number;
}
export interface TextLine {
  text: string;
  page: number;
  confidence: number;
}
export interface DocumentMeta {
  fileName: string;
  fileType: string;
  pageCount: number;
  capturedAt: string;
}
export interface Payload {
  document: DocumentMeta;
  fields: Record<string, string>;
  fieldDetails: Field[];
  unmatchedText: TextLine[];
  rawText: string;
}
