import type { DocumentMeta, Field, Payload, TextLine } from "../types";
import { validateFields } from "./parser";
export function buildPayload(
  document: DocumentMeta,
  fieldDetails: Field[],
  unmatchedText: TextLine[],
  rawText: string,
): Payload {
  const errors = validateFields(fieldDetails);
  if (errors.length) throw new Error(errors.join(" "));
  return {
    document: { ...document },
    fields: Object.fromEntries(fieldDetails.map((f) => [f.key, f.value])),
    fieldDetails: fieldDetails.map((f) => ({ ...f })),
    unmatchedText: unmatchedText.map((t) => ({ ...t })),
    rawText,
  };
}
