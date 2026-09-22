import * as pdfjs from "pdfjs-dist";
import workerUrl from "pdfjs-dist/build/pdf.worker.min.mjs?url";
import { createWorker } from "tesseract.js";
import type { TextLine } from "../types";
pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
export interface ScanSource {
  file: File;
  url: string;
  pdf?: pdfjs.PDFDocumentProxy;
  pageCount: number;
}
export interface OcrService {
  extract(
    source: ScanSource,
    onProgress: (value: number, status: string) => void,
  ): Promise<TextLine[]>;
}
export async function openDocument(file: File): Promise<ScanSource> {
  const url = URL.createObjectURL(file);
  let loadingTask: pdfjs.PDFDocumentLoadingTask | undefined;
  try {
    if (/\.pdf$/i.test(file.name)) {
      loadingTask = pdfjs.getDocument({ data: await file.arrayBuffer() });
      const pdf = await loadingTask.promise;
      return { file, url, pdf, pageCount: pdf.numPages };
    }
    const img = new Image();
    img.src = url;
    await img.decode();
    if (!img.naturalWidth) throw new Error("Empty image");
    return { file, url, pageCount: 1 };
  } catch {
    URL.revokeObjectURL(url);
    await loadingTask?.destroy();
    throw new Error(
      "This document cannot be read. It may be damaged, password-protected, or in an unsupported format.",
    );
  }
}
export function closeDocument(source: ScanSource) {
  URL.revokeObjectURL(source.url);
  void source.pdf?.loadingTask.destroy();
}
export async function renderPage(
  source: ScanSource,
  pageNumber: number,
  canvas: HTMLCanvasElement,
  scale = 1.5,
) {
  if (!source.pdf) return;
  const page = await source.pdf.getPage(pageNumber);
  const base = page.getViewport({ scale });
  const factor = Math.min(1, 2400 / Math.max(base.width, base.height));
  const viewport = page.getViewport({ scale: scale * factor });
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  await page.render({
    canvasContext: canvas.getContext("2d")!,
    canvas,
    viewport,
  }).promise;
}
export const localOcr: OcrService = {
  async extract(source, onProgress) {
    let worker: Awaited<ReturnType<typeof createWorker>> | undefined;
    let current = 1;
    const recognize = async (input: string | HTMLCanvasElement) => {
      try {
        worker ??= await createWorker("eng", 1, {
          workerPath: "/ocr/worker.min.js",
          corePath: "/ocr",
          langPath: "/ocr",
          logger: (m) =>
            onProgress(
              Math.round(
                ((current - 1 + (m.progress || 0)) / source.pageCount) * 100,
              ),
              `Page ${current} of ${source.pageCount} · ${m.status}`,
            ),
        });
        const { data } = await worker.recognize(
          input,
          {},
          { blocks: true, text: true },
        );
        const lines: TextLine[] = [];
        for (const block of data.blocks ?? [])
          for (const paragraph of block.paragraphs)
            for (const line of paragraph.lines) {
              let text = "";
              let last = 0;
              const height = line.bbox.y1 - line.bbox.y0;
              for (const word of line.words) {
                text +=
                  (text
                    ? word.bbox.x0 - last > height * 1.1
                      ? "  "
                      : " "
                    : "") + word.text;
                last = word.bbox.x1;
              }
              lines.push({
                text,
                page: current,
                confidence: line.confidence / 100,
              });
            }
        return lines.length
          ? lines
          : data.text
              .split("\n")
              .filter((t) => t.trim())
              .map((text) => ({
                text,
                page: current,
                confidence: data.confidence / 100,
              }));
      } catch {
        throw new Error(
          navigator.onLine
            ? "OCR could not load or read this document. Try a sharper image, then retry."
            : "OCR resources are unavailable offline. Connect once and let the app finish caching, then retry.",
        );
      }
    };
    try {
      const lines: TextLine[] = [];
      for (current = 1; current <= source.pageCount; current++) {
        onProgress(
          Math.round(((current - 1) / source.pageCount) * 100),
          `Reading page ${current} of ${source.pageCount}`,
        );
        if (source.pdf) {
          const page = await source.pdf.getPage(current);
          const content = await page.getTextContent();
          const items = content.items
            .filter((item) => "str" in item)
            .filter((item) => item.str.trim());
          if (
            items
              .map((item) => item.str)
              .join("")
              .trim().length >= 3
          ) {
            const rows: { y: number; items: typeof items }[] = [];
            for (const item of items) {
              const y = item.transform[5];
              let row = rows.find(
                (r) => Math.abs(r.y - y) < Math.max(3, item.height * 0.4),
              );
              if (!row) {
                row = { y, items: [] };
                rows.push(row);
              }
              row.items.push(item);
            }
            for (const row of rows.sort((a, b) => b.y - a.y)) {
              let text = "";
              let end = 0;
              for (const item of row.items.sort(
                (a, b) => a.transform[4] - b.transform[4],
              )) {
                text +=
                  (text
                    ? item.transform[4] - end > Math.max(10, item.height)
                      ? "  "
                      : " "
                    : "") + item.str;
                end = item.transform[4] + item.width;
              }
              lines.push({ text, page: current, confidence: 0.98 });
            }
          } else {
            const canvas = document.createElement("canvas");
            await renderPage(source, current, canvas, 2);
            lines.push(...(await recognize(canvas)));
            canvas.width = 0;
            canvas.height = 0;
          }
        } else lines.push(...(await recognize(source.url)));
      }
      if (!lines.some((l) => l.text.trim()))
        throw new Error(
          "No readable text was found. Try a clearer document with printed text.",
        );
      onProgress(100, "Extraction complete");
      return lines;
    } finally {
      await worker?.terminate();
    }
  },
};
