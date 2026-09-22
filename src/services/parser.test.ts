import { describe, it, expect } from "vitest";
import { normalizeKey, parseLines, validateFields } from "./parser";
import { buildPayload } from "./payload";
import { validateFile } from "./files";
const line = (text: string, page = 1, confidence = 0.98) => ({
  text,
  page,
  confidence,
});
const meta = {
  fileName: "invoice.pdf",
  fileType: "application/pdf",
  pageCount: 2,
  capturedAt: "2026-09-22T10:30:00.000Z",
};
describe("field parsing", () => {
  it("recognizes colon, spaced dash, table cells, and preserves labels", () => {
    const result = parseLines([
      line("Name: Juan Dela Cruz"),
      line("Invoice Number - INV-001"),
      line("Total Amount  1500.00"),
      line("Tax | 50"),
    ]);
    expect(result.fields.map((f) => [f.key, f.value])).toEqual([
      ["name", "Juan Dela Cruz"],
      ["invoiceNumber", "INV-001"],
      ["totalAmount", "1500.00"],
      ["tax", "50"],
    ]);
    expect(result.fields[1].label).toBe("Invoice Number");
  });
  it("pairs familiar labels above values conservatively", () => {
    const result = parseLines([line("Customer Name"), line("Juan Dela Cruz")]);
    expect(result.fields[0].confidence).toBe(0.65);
    expect(result.fields[0].value).toBe("Juan Dela Cruz");
  });
  it("never pairs across pages and retains unmatched content", () => {
    const result = parseLines([
      line("Name"),
      line("Juan", 2),
      line("Thank you for your business", 2),
    ]);
    expect(result.fields).toHaveLength(0);
    expect(result.unmatchedText).toHaveLength(3);
  });
  it("does not interpret an invoice identifier hyphen as separator", () => {
    expect(parseLines([line("INV-001")]).unmatchedText).toHaveLength(1);
  });
  it("preserves confidence and page", () => {
    expect(parseLines([line("Name: Ana", 2, 0.42)]).fields[0]).toMatchObject({
      page: 2,
      confidence: 0.42,
    });
  });
  it("normalizes labels", () =>
    expect(normalizeKey("Invoice NUMBER")).toBe("invoiceNumber"));
});
describe("payload integrity", () => {
  it("detects duplicates without dropping fields", () => {
    const { fields } = parseLines([line("Name: One"), line("Name: Two")]);
    expect(fields).toHaveLength(2);
    expect(validateFields(fields).join(" ")).toContain("Duplicate key");
    expect(() => buildPayload(meta, fields, [], "")).toThrow();
  });
  it("rejects blank keys and control characters", () => {
    const field = parseLines([line("Name: One")]).fields[0];
    expect(
      validateFields([
        { ...field, key: " " },
        { ...field, key: "bad\nkey" },
      ]),
    ).toHaveLength(2);
  });
  it("supports valid JSON string property names, including special keys", () => {
    const field = parseLines([line("Name: One")]).fields[0];
    const result = buildPayload(
      meta,
      [
        { ...field, key: "__proto__" },
        { ...field, key: "customer name" },
      ],
      [],
      "Name: One",
    );
    expect(JSON.parse(JSON.stringify(result)).fields.__proto__).toBe("One");
  });
  it("includes exact reviewed fields, original text, metadata and unmatched text", () => {
    const { fields, unmatchedText } = parseLines([
      line("Name: Ana"),
      line("Hello"),
    ]);
    const payload = buildPayload(
      meta,
      fields,
      unmatchedText,
      "Name: Ana\nHello",
    );
    expect(payload).toEqual({
      document: meta,
      fields: { name: "Ana" },
      fieldDetails: fields,
      unmatchedText,
      rawText: "Name: Ana\nHello",
    });
    fields[0].value = "changed";
    expect(payload.fieldDetails[0].value).toBe("Ana");
    expect(JSON.parse(JSON.stringify(payload))).toEqual(payload);
  });
});
describe("input validation", () => {
  it("rejects empty, unsupported, and oversized files", () => {
    expect(() => validateFile(new File([], "empty.pdf"))).toThrow("empty");
    expect(() => validateFile(new File(["x"], "file.exe"))).toThrow(
      "Unsupported",
    );
    expect(() =>
      validateFile({ name: "large.pdf", size: 30 * 1024 * 1024 } as File),
    ).toThrow("exceeds");
  });
});
