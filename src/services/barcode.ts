import { BarcodeFormat, BrowserMultiFormatReader } from "@zxing/browser";
import { DecodeHintType } from "@zxing/library";

const scale = 0.5;

export function createBarcodeReader() {
  return new BrowserMultiFormatReader(new Map<DecodeHintType, unknown>([
    [DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.CODE_128]],
    [DecodeHintType.TRY_HARDER, true],
  ]));
}

export function decodeBarcode(
  reader: BrowserMultiFormatReader,
  sheet: HTMLCanvasElement,
  region: { x: number; y: number; width: number; height: number },
): string | null {
  const crop = document.createElement("canvas");
  crop.width = Math.round(region.width * scale);
  crop.height = Math.round(region.height * scale);
  const ctx = crop.getContext("2d");
  if (!ctx) return null;
  // The rectified sheet is already a raster at this scale. Keep barcode
  // edges aligned to its pixels; smoothing fractional crop origins blurs
  // one-pixel-wide modules.
  ctx.imageSmoothingEnabled = false;
  ctx.drawImage(sheet, region.x * scale, region.y * scale, region.width * scale, region.height * scale, 0, 0, crop.width, crop.height);

  const decode = (image: HTMLCanvasElement) => {
    try {
      const value = reader.decodeFromCanvas(image).getText().trim();
      return value || null;
    } catch {
      return null;
    }
  };

  const direct = decode(crop);
  if (direct) return direct;

  // Small Code 128 bars can approach one pixel per module on a phone scan.
  // Retry after nearest-neighbor enlargement and a few binarization levels.
  const pixels = ctx.getImageData(0, 0, crop.width, crop.height);
  for (const threshold of [120, 165, 205]) {
    const enlarged = document.createElement("canvas");
    enlarged.width = crop.width * 3;
    enlarged.height = crop.height * 3;
    const enlargedContext = enlarged.getContext("2d");
    if (!enlargedContext) continue;
    const binary = enlargedContext.createImageData(crop.width, crop.height);
    for (let i = 0; i < pixels.data.length; i += 4) {
      const gray = pixels.data[i] * 0.299 + pixels.data[i + 1] * 0.587 + pixels.data[i + 2] * 0.114;
      const value = gray < threshold ? 0 : 255;
      binary.data[i] = binary.data[i + 1] = binary.data[i + 2] = value;
      binary.data[i + 3] = 255;
    }
    const thresholded = document.createElement("canvas");
    thresholded.width = crop.width;
    thresholded.height = crop.height;
    thresholded.getContext("2d")?.putImageData(binary, 0, 0);
    enlargedContext.imageSmoothingEnabled = false;
    enlargedContext.drawImage(thresholded, 0, 0, enlarged.width, enlarged.height);
    const value = decode(enlarged);
    if (value) return value;
  }
  return null;
}
