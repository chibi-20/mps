import { describe, expect, it } from "vitest";
import {
  fourSubjectBubbleCenter,
  mapFourSubjectTemplate,
  parseStudentAssessmentBarcode,
  parseSubjectBarcode,
  readFourSubjectAnswers,
  studentAssessmentBarcodeRegion,
  subjectBarcodeRegion,
} from "./fiveSubjectOmr";
import type { Corners } from "./omr";

const OUTPUT_SCALE = 0.5;

function blankRectifiedSheet() {
  const width = 1275;
  const height = 1650;
  const data = new Uint8ClampedArray(width * height * 4).fill(255);
  // Each unfilled printed bubble has a dark answer letter in its center.
  for (let column = 0; column < 4; column++) {
    for (let question = 0; question < 50; question++) {
      for (let choice = 0; choice < 4; choice++) {
        const center = fourSubjectBubbleCenter(column, question, choice);
        const cx = center.x * OUTPUT_SCALE;
        const cy = center.y * OUTPUT_SCALE;
        for (let y = Math.floor(cy - 2.5); y <= Math.ceil(cy + 2.5); y++) {
          for (let x = Math.floor(cx - 2.5); x <= Math.ceil(cx + 2.5); x++) {
            if ((x - cx) ** 2 + (y - cy) ** 2 > 2.5 ** 2) continue;
            const index = (y * width + x) * 4;
            data[index] = data[index + 1] = data[index + 2] = 0;
            data[index + 3] = 255;
          }
        }
      }
    }
  }
  const image = { width, height, data } as ImageData;
  const context = { getImageData: () => image };
  const canvas = {
    width,
    height,
    getContext: () => context,
  } as unknown as HTMLCanvasElement;
  return { canvas, data };
}

function fillBubble(data: Uint8ClampedArray, column: number, question: number, choice: number) {
  const center = fourSubjectBubbleCenter(column, question, choice);
  const cx = center.x * OUTPUT_SCALE;
  const cy = center.y * OUTPUT_SCALE;
  for (let y = Math.floor(cy - 6.5); y <= Math.ceil(cy + 6.5); y++) {
    for (let x = Math.floor(cx - 6.5); x <= Math.ceil(cx + 6.5); x++) {
      if ((x - cx) ** 2 + (y - cy) ** 2 > 6.5 ** 2) continue;
      const index = (y * 1275 + x) * 4;
      data[index] = data[index + 1] = data[index + 2] = 0;
      data[index + 3] = 255;
    }
  }
}

describe("MPS four-subject answer sheet", () => {
  it("maps every bubble inside the detected page for the four sample scans", () => {
    // Registration-square centers detected from the four attached scans.
    const sampleCorners = [
      [{ x: 100, y: 154 }, { x: 1240, y: 150 }, { x: 1240, y: 1710 }, { x: 96, y: 1721 }],
      [{ x: 90, y: 148 }, { x: 1220, y: 145 }, { x: 1220, y: 1717 }, { x: 88, y: 1722 }],
      [{ x: 91, y: 150 }, { x: 1208, y: 149 }, { x: 1212, y: 1710 }, { x: 87, y: 1710 }],
      [{ x: 93, y: 148 }, { x: 1239, y: 146 }, { x: 1240, y: 1702 }, { x: 93, y: 1701 }],
    ] as const;

    for (const corners of sampleCorners) {
      const map = mapFourSubjectTemplate(corners as unknown as Corners);
      const allBubbles = Array.from({ length: 4 }, (_, column) =>
        Array.from({ length: 50 }, (_, question) =>
          Array.from({ length: 4 }, (_, choice) => map(fourSubjectBubbleCenter(column, question, choice))),
        ),
      ).flat(2);
      const minX = Math.min(...corners.map((point) => point.x));
      const maxX = Math.max(...corners.map((point) => point.x));
      const minY = Math.min(...corners.map((point) => point.y));
      const maxY = Math.max(...corners.map((point) => point.y));

      expect(allBubbles).toHaveLength(800);
      expect(allBubbles.every((point) => point.x > minX && point.x < maxX && point.y > minY && point.y < maxY)).toBe(true);
      const mappedTopLeft = map({ x: 123, y: 131 });
      const mappedBottomRight = map({ x: 2427, y: 3260 });
      expect(mappedTopLeft.x).toBeCloseTo(corners[0].x);
      expect(mappedTopLeft.y).toBeCloseTo(corners[0].y);
      expect(mappedBottomRight.x).toBeCloseTo(corners[2].x);
      expect(mappedBottomRight.y).toBeCloseTo(corners[2].y);
    }
  });

  it("maps all 800 bubble centers to the printed four-column layout", () => {
    const centers = Array.from({ length: 4 }, (_, column) =>
      Array.from({ length: 50 }, (_, question) =>
        Array.from({ length: 4 }, (_, choice) => fourSubjectBubbleCenter(column, question, choice)),
      ),
    );

    expect(centers.flat(2)).toHaveLength(800);
    expect(centers[0][0]).toEqual([
      { x: 235, y: 380 },
      { x: 340, y: 380 },
      { x: 445, y: 380 },
      { x: 550, y: 380 },
    ]);
    expect(centers[3][49][3]).toEqual({ x: 2290, y: 3222 });
    expect(() => fourSubjectBubbleCenter(4, 0, 0)).toThrow(RangeError);
    expect(() => fourSubjectBubbleCenter(0, 50, 0)).toThrow(RangeError);
    expect(() => fourSubjectBubbleCenter(0, 0, 4)).toThrow(RangeError);
  });

  it("reads marked, multiple, and blank answers across all four columns", () => {
    const { canvas, data } = blankRectifiedSheet();
    fillBubble(data, 0, 0, 0);
    fillBubble(data, 2, 24, 2);
    fillBubble(data, 3, 49, 3);
    fillBubble(data, 1, 4, 0);
    fillBubble(data, 1, 4, 2);

    const result = readFourSubjectAnswers(canvas);

    expect(result).toHaveLength(4);
    expect(result.every((column) => column.length === 50)).toBe(true);
    expect(result[0][0]).toMatchObject({ choice: "A", status: "marked" });
    expect(result[1][4]).toMatchObject({ choice: null, status: "multiple" });
    expect(result[2][24]).toMatchObject({ choice: "C", status: "marked" });
    expect(result[3][49]).toMatchObject({ choice: "D", status: "marked" });
    expect(result[3][49]).toMatchObject({ choice: null, status: "blank" });
  });

  it("uses barcode crop regions and parses the new layout's ID formats", () => {
    expect(studentAssessmentBarcodeRegion).toEqual({ x: 1640, y: 145, width: 750, height: 105 });
    expect(subjectBarcodeRegion(3)).toEqual({ x: 1875, y: 295, width: 525, height: 50 });
    expect(parseStudentAssessmentBarcode("123_00042")).toEqual({ assessmentId: "123", studentId: "00042" });
    expect(parseSubjectBarcode("9_00042_17")).toEqual({ subjectId: "9", studentId: "00042", sectionId: "17" });
    expect(parseSubjectBarcode("9_17")).toEqual({ subjectId: "9", studentId: "", sectionId: "17" });
    expect(parseSubjectBarcode("invalid")).toEqual({ subjectId: "", studentId: "", sectionId: "" });
  });
});
