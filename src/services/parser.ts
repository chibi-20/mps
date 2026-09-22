import type { Field, TextLine } from "../types";
export function normalizeKey(label: string): string {
  const words =
    label
      .normalize("NFKD")
      .replace(/[\u0300-\u036f]/g, "")
      .match(/[\p{L}\p{N}]+/gu) ?? [];
  return words
    .map((word, i) =>
      i
        ? word[0].toUpperCase() + word.slice(1).toLowerCase()
        : word.toLowerCase(),
    )
    .join("");
}
const knownLabel =
  /^(?:name|customer name|invoice number|invoice date|date|address|email|phone|total(?: amount)?|subtotal|tax|account number|reference|due date|bill to|quantity|description)$/i;
export function parseLines(lines: TextLine[]): {
  fields: Field[];
  unmatchedText: TextLine[];
} {
  const fields: Field[] = [];
  const unmatchedText: TextLine[] = [];
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const text = line.text.trim();
    if (!text) continue;
    const match = text.match(
      /^(.{1,70}?)\s*(?::|\s[-–—]\s|\t+|\s{2,}|\s*\|\s*)\s*(\S.*)$/,
    );
    let label = match?.[1].trim();
    let value = match?.[2].trim();
    let certainty = 0.95;
    if (
      !match &&
      knownLabel.test(text.replace(/:$/, "")) &&
      lines[i + 1]?.page === line.page &&
      !/[:|]/.test(lines[i + 1].text) &&
      !knownLabel.test(lines[i + 1].text.trim())
    ) {
      label = text.replace(/:$/, "");
      value = lines[++i].text.trim();
      certainty = 0.65;
    }
    if (label && value && /\p{L}/u.test(label)) {
      fields.push({
        key: normalizeKey(label),
        label,
        value,
        confidence: Math.min(line.confidence, certainty),
        page: line.page,
      });
    } else unmatchedText.push(line);
  }
  return { fields, unmatchedText };
}
export function validateFields(fields: Field[]): string[] {
  const errors: string[] = [];
  const seen = new Set<string>();
  fields.forEach((f, i) => {
    if (!f.key.trim()) errors.push(`Field ${i + 1}: key is required.`);
    else if (/[\u0000-\u001f]/.test(f.key))
      errors.push(`Field ${i + 1}: remove control characters from the key.`);
    if (seen.has(f.key))
      errors.push(
        `Duplicate key: ${f.key}. Rename or remove it before exporting.`,
      );
    seen.add(f.key);
  });
  return errors;
}
