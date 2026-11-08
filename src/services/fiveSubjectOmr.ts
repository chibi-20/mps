import { detectCorners, project, type Corners, type Point } from "./omr";

export const TEMPLATE_WIDTH = 2550;
export const TEMPLATE_HEIGHT = 3300;
const OUTPUT_SCALE = 0.5;
export const FOUR_SUBJECT_REFERENCE_CORNERS: Corners = [
  { x: 123, y: 131 },
  { x: 2427, y: 131 },
  { x: 2427, y: 3260 },
  { x: 123, y: 3260 },
];
export const FOUR_SUBJECT_BUBBLE_COLUMNS = [235, 815, 1395, 1975] as const;
export const ANSWER_ROW_START_Y = 380;
export const ANSWER_ROW_SPACING = 58;
export const ANSWER_CHOICE_SPACING = 105;
export const FOUR_SUBJECT_REFERENCE_BOXES: Corners[] = Array.from({ length: 4 }, (_, column) => {
  const left = 120 + column * 580;
  return [
    { x: left, y: 250 },
    { x: left + 555, y: 250 },
    { x: left + 555, y: 3270 },
    { x: left, y: 3270 },
  ];
});

/** Coordinates use zero-based column, question, and choice indexes. */
export function fourSubjectBubbleCenter(column: number, question: number, choice: number): Point {
  if (!Number.isInteger(column) || column < 0 || column >= FOUR_SUBJECT_BUBBLE_COLUMNS.length) {
    throw new RangeError("Subject column must be between 1 and 4.");
  }
  if (!Number.isInteger(question) || question < 0 || question >= 50) {
    throw new RangeError("Question must be between 1 and 50.");
  }
  if (!Number.isInteger(choice) || choice < 0 || choice >= 4) {
    throw new RangeError("Answer choice must be between A and D.");
  }
  return {
    x: FOUR_SUBJECT_BUBBLE_COLUMNS[column] + choice * ANSWER_CHOICE_SPACING,
    y: ANSWER_ROW_START_Y + question * ANSWER_ROW_SPACING,
  };
}

export function findSheetCorners(image: ImageData): Corners | null {
  return detectCorners(image);
}

/** Maps template-space coordinates directly to pixel coordinates in a photo. */
export function mapFourSubjectTemplate(corners: Corners): (point: Point) => Point {
  return project(FOUR_SUBJECT_REFERENCE_CORNERS, corners);
}

export function rectifyFourSubjectSheet(
  source: ImageData,
  corners: Corners,
): HTMLCanvasElement {
  const width = Math.round(TEMPLATE_WIDTH * OUTPUT_SCALE);
  const height = Math.round(TEMPLATE_HEIGHT * OUTPUT_SCALE);
  const projectPoint = mapFourSubjectTemplate(corners);
  const output = document.createElement("canvas");
  output.width = width;
  output.height = height;
  const ctx = output.getContext("2d", { willReadFrequently: true });
  if (!ctx) throw new Error("This browser cannot process the answer sheet image.");
  const rectified = ctx.createImageData(width, height);
  for (let y = 0; y < height; y++) {
    const templateY = y / OUTPUT_SCALE;
    for (let x = 0; x < width; x++) {
      const p = projectPoint({ x: x / OUTPUT_SCALE, y: templateY });
      const sx = Math.round(p.x);
      const sy = Math.round(p.y);
      const dest = (y * width + x) * 4;
      if (sx < 0 || sy < 0 || sx >= source.width || sy >= source.height) {
        rectified.data[dest] = 255;
        rectified.data[dest + 1] = 255;
        rectified.data[dest + 2] = 255;
        rectified.data[dest + 3] = 255;
        continue;
      }
      const src = (sy * source.width + sx) * 4;
      rectified.data[dest] = source.data[src];
      rectified.data[dest + 1] = source.data[src + 1];
      rectified.data[dest + 2] = source.data[src + 2];
      rectified.data[dest + 3] = 255;
    }
  }
  ctx.putImageData(rectified, 0, 0);
  return output;
}

function lineStrength(image: ImageData, axis: "x" | "y", fixed: number, start: number, end: number): number {
  let dark = 0;
  const length = Math.max(1, Math.floor(end) - Math.ceil(start) + 1);
  for (let offset = Math.ceil(start); offset <= end; offset++) {
    const x = Math.round(axis === "x" ? fixed : offset);
    const y = Math.round(axis === "y" ? fixed : offset);
    if (x < 0 || y < 0 || x >= image.width || y >= image.height) continue;
    const index = (y * image.width + x) * 4;
    const gray = image.data[index] * 0.299 + image.data[index + 1] * 0.587 + image.data[index + 2] * 0.114;
    if (gray < 145) dark++;
  }
  return dark / length;
}

function trackLine(image: ImageData, axis: "x" | "y", expected: number, start: number, end: number): number | null {
  let best = expected;
  let strength = 0;
  for (let offset = -10; offset <= 10; offset++) {
    const candidate = expected + offset;
    const score = lineStrength(image, axis, candidate, start, end);
    if (score > strength) { strength = score; best = candidate; }
  }
  return strength >= 0.24 ? best : null;
}

/** Track the printed outline of each subject on the page-rectified image. */
export function trackFourSubjectBoxes(rectified: HTMLCanvasElement): Corners[] {
  const ctx = rectified.getContext("2d", { willReadFrequently: true });
  if (!ctx) return FOUR_SUBJECT_REFERENCE_BOXES;
  const image = ctx.getImageData(0, 0, rectified.width, rectified.height);
  const scale = OUTPUT_SCALE;
  return FOUR_SUBJECT_REFERENCE_BOXES.map((reference) => {
    const [topLeft, topRight, bottomRight, bottomLeft] = reference;
    const controlPoints = [topLeft, topRight, bottomRight, bottomLeft].map((point) =>
      trackControlPoint(image, { x: point.x * scale, y: point.y * scale }),
    );
    if (controlPoints.every((point): point is Point => point !== null)) {
      return controlPoints.map((point) => ({ x: point.x / scale, y: point.y / scale })) as Corners;
    }
    const left = topLeft.x * scale;
    const right = topRight.x * scale;
    const top = topLeft.y * scale;
    const bottom = bottomLeft.y * scale;
    const leftTopX = trackLine(image, "x", left, top + 12, top + 100);
    const rightTopX = trackLine(image, "x", right, top + 12, top + 100);
    const leftBottomX = trackLine(image, "x", left, bottom - 100, bottom - 12);
    const rightBottomX = trackLine(image, "x", right, bottom - 100, bottom - 12);
    const topLeftY = trackLine(image, "y", top, left + 12, left + 100);
    const topRightY = trackLine(image, "y", top, right - 100, right - 12);
    const bottomLeftY = trackLine(image, "y", bottom, left + 12, left + 100);
    const bottomRightY = trackLine(image, "y", bottom, right - 100, right - 12);
    if ([leftTopX, rightTopX, leftBottomX, rightBottomX, topLeftY, topRightY, bottomLeftY, bottomRightY].some((value) => value === null)) {
      return reference;
    }
    return [
      { x: leftTopX! / scale, y: topLeftY! / scale },
      { x: rightTopX! / scale, y: topRightY! / scale },
      { x: rightBottomX! / scale, y: bottomRightY! / scale },
      { x: leftBottomX! / scale, y: bottomLeftY! / scale },
    ] as Corners;
  });
}

/** Find a printed filled square near an expected subject-box corner. */
function trackControlPoint(image: ImageData, expected: Point): Point | null {
  let best: Point | null = null;
  let bestScore = 0;
  for (let dy = -8; dy <= 8; dy++) {
    for (let dx = -8; dx <= 8; dx++) {
      let dark = 0;
      for (let py = -2; py <= 2; py++) {
        for (let px = -2; px <= 2; px++) {
          if (grayAt(image, Math.round(expected.x + dx + px), Math.round(expected.y + dy + py)) < 145) dark++;
        }
      }
      const score = dark / 25 - Math.hypot(dx, dy) * 0.0005;
      if (score > bestScore) { bestScore = score; best = { x: expected.x + dx, y: expected.y + dy }; }
    }
  }
  return bestScore >= 0.72 ? best : null;
}

function bubbleFill(data: ImageData, center: Point): number {
  let dark = 0;
  let total = 0;
  // The printed A/B/C/D glyph fills the center of every bubble, including
  // blank ones. Measure an interior ring outside that glyph and inside the
  // printed circle so only pencil/pen fill contributes to the score.
  const innerRadius = 3.5;
  const outerRadius = 8;
  const threshold = 170;
  for (let dy = -outerRadius; dy <= outerRadius; dy++) {
    for (let dx = -outerRadius; dx <= outerRadius; dx++) {
      const distanceSquared = dx * dx + dy * dy;
      if (distanceSquared < innerRadius * innerRadius || distanceSquared > outerRadius * outerRadius) continue;
      const x = Math.round(center.x + dx);
      const y = Math.round(center.y + dy);
      if (x < 0 || y < 0 || x >= data.width || y >= data.height) continue;
      const index = (y * data.width + x) * 4;
      const gray = data.data[index] * 0.299 + data.data[index + 1] * 0.587 + data.data[index + 2] * 0.114;
      total++;
      if (gray < threshold) dark++;
    }
  }
  return total ? dark / total : 0;
}

export interface BubbleAnswer {
  choice: "A" | "B" | "C" | "D" | null;
  status: "marked" | "blank" | "multiple" | "uncertain";
  confidence: number;
}

export type FourSubjectBubbleCenters = Point[][][];

export function mapFourSubjectBubble(column: number, question: number, choice: number, subjectBoxes?: Corners[], trackedCenters?: FourSubjectBubbleCenters): Point {
  const measured = trackedCenters?.[column]?.[question]?.[choice];
  if (measured) return measured;
  const center = fourSubjectBubbleCenter(column, question, choice);
  return subjectBoxes?.[column]
    ? project(FOUR_SUBJECT_REFERENCE_BOXES[column], subjectBoxes[column])(center)
    : center;
}

function grayAt(data: ImageData, x: number, y: number): number {
  if (x < 0 || y < 0 || x >= data.width || y >= data.height) return 255;
  const index = (y * data.width + x) * 4;
  return data.data[index] * 0.299 + data.data[index + 1] * 0.587 + data.data[index + 2] * 0.114;
}

function locateBubbleCenter(image: ImageData, expected: Point): Point {
  const cx = expected.x * OUTPUT_SCALE;
  const cy = expected.y * OUTPUT_SCALE;
  const radii = [12.5, 13.5, 14.5, 15.5];
  const angles = Array.from({ length: 24 }, (_, i) => i * Math.PI / 12);
  let best = { x: cx, y: cy };
  let bestScore = -1;
  // Match the printed circular outline rather than its center letter or a
  // filled mark. This corrects small local shifts that remain after warping.
  for (let dy = -4; dy <= 4; dy++) {
    for (let dx = -4; dx <= 4; dx++) {
      const candidateX = cx + dx;
      const candidateY = cy + dy;
      let score = 0;
      for (const angle of angles) {
        let strongest = 0;
        for (const radius of radii) {
          const x = Math.round(candidateX + Math.cos(angle) * radius);
          const y = Math.round(candidateY + Math.sin(angle) * radius);
          strongest = Math.max(strongest, (190 - grayAt(image, x, y)) / 190);
        }
        score += Math.max(0, strongest);
      }
      // Prefer the expected location when two candidates match the ring equally.
      score -= Math.hypot(dx, dy) * 0.002;
      if (score > bestScore) { bestScore = score; best = { x: candidateX, y: candidateY }; }
    }
  }
  return { x: best.x / OUTPUT_SCALE, y: best.y / OUTPUT_SCALE };
}

export function trackFourSubjectBubbleCenters(rectified: HTMLCanvasElement, subjectBoxes?: Corners[]): FourSubjectBubbleCenters {
  const ctx = rectified.getContext("2d", { willReadFrequently: true });
  if (!ctx) throw new Error("This browser cannot track answer bubbles.");
  const image = ctx.getImageData(0, 0, rectified.width, rectified.height);
  return FOUR_SUBJECT_BUBBLE_COLUMNS.map((_, column) => {
    const box = subjectBoxes?.[column];
    const mapLocal = box ? project(FOUR_SUBJECT_REFERENCE_BOXES[column], box) : (point: Point) => point;
    return Array.from({ length: 50 }, (_, question) => Array.from({ length: 4 }, (_, choice) => {
      const expected = mapLocal(fourSubjectBubbleCenter(column, question, choice));
      return locateBubbleCenter(image, expected);
    }));
  });
}

export function readFourSubjectAnswers(rectified: HTMLCanvasElement, subjectBoxes?: Corners[], trackedCenters?: FourSubjectBubbleCenters): BubbleAnswer[][] {
  const ctx = rectified.getContext("2d", { willReadFrequently: true });
  if (!ctx) throw new Error("This browser cannot read answer bubbles.");
  const image = ctx.getImageData(0, 0, rectified.width, rectified.height);
  return FOUR_SUBJECT_BUBBLE_COLUMNS.map((_, column) => {
    const box = subjectBoxes?.[column];
    const mapLocal = box ? project(FOUR_SUBJECT_REFERENCE_BOXES[column], box) : (point: Point) => point;
    return Array.from({ length: 50 }, (_, itemIndex) => {
      const scores = [0, 1, 2, 3].map((choice) => {
        const center = fourSubjectBubbleCenter(column, itemIndex, choice);
        const tracked = mapLocal(center);
        const measured = trackedCenters?.[column]?.[itemIndex]?.[choice] ?? tracked;
        return bubbleFill(image, { x: measured.x * OUTPUT_SCALE, y: measured.y * OUTPUT_SCALE });
      });
      const marked = scores.map((score, i) => ({ score, i })).filter((x) => x.score >= 0.22);
      const sorted = [...scores].sort((a, b) => b - a);
      if (marked.length > 1) return { choice: null, status: "multiple" as const, confidence: 0.15 };
      if (marked.length === 1 && sorted[0] - sorted[1] > 0.1) {
        return {
          choice: (['A', 'B', 'C', 'D'] as const)[marked[0].i],
          status: "marked" as const,
          confidence: Math.min(0.99, 0.6 + (sorted[0] - sorted[1]) * 0.45),
        };
      }
      if (sorted[0] > 0.12) return { choice: null, status: "uncertain" as const, confidence: 0.2 };
      return { choice: null, status: "blank" as const, confidence: 0.65 };
    });
  });
}

export const studentAssessmentBarcodeRegion = { x: 1640, y: 145, width: 750, height: 105 };
export function subjectBarcodeRegion(column: number) {
  // Keep the crop clear of the first answer-bubble row below the barcode.
  return { x: 135 + column * 580, y: 295, width: 525, height: 50 };
}

export function parseStudentAssessmentBarcode(value: string | null) {
  const match = value?.trim().match(/^(\d+)_([^_]+)$/);
  if (!match) return { assessmentId: "", studentId: "" };
  return { assessmentId: match[1], studentId: match[2].trim() };
}

export function parseSubjectBarcode(value: string | null) {
  const match = value?.trim().match(/^(\d+)_([^_]+)(?:_([^_]+))?$/);
  if (!match) return { subjectId: "", studentId: "", sectionId: "" };
  if (match[3] === undefined) return { subjectId: match[1], studentId: "", sectionId: match[2].trim() };
  return { subjectId: match[1], studentId: match[2].trim(), sectionId: match[3].trim() };
}
