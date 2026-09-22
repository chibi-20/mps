import { describe, it, expect } from "vitest";
import {
  classifyBubbles,
  project,
  referenceCorners,
  answerCenters,
  studentIdCenters,
  readSheet,
} from "./omr";
describe("answer sheet recognition", () => {
  it("retains blank answers without guessing", () =>
    expect(
      classifyBubbles([0.1, 0.2, 0.12, 0.15], ["A", "B", "C", "D"]),
    ).toMatchObject({ value: "", status: "blank" }));
  it("recognizes one marked bubble", () =>
    expect(
      classifyBubbles([0.1, 0.8, 0.1, 0.2], ["A", "B", "C", "D"]),
    ).toMatchObject({ value: "B", status: "marked" }));
  it("flags multiple marked answers without choosing one", () =>
    expect(
      classifyBubbles([0.8, 0.1, 0.85, 0.1], ["A", "B", "C", "D"]),
    ).toMatchObject({ value: "A, C", status: "multiple" }));
  it("flags faint marks and close scores", () => {
    expect(
      classifyBubbles([0.4, 0.1, 0.2, 0.1], ["A", "B", "C", "D"]).status,
    ).toBe("uncertain");
    expect(
      classifyBubbles([0.58, 0.52, 0.2, 0.1], ["A", "B", "C", "D"]).status,
    ).toBe("uncertain");
  });
  it("maps a skewed quadrilateral to all four target markers", () => {
    const target = referenceCorners.map((p) => ({
      x: p.x * 0.8 + 30,
      y: p.y * 0.6 + 10,
    })) as typeof referenceCorners;
    const transform = project(referenceCorners, target);
    referenceCorners.forEach((point, i) => {
      expect(transform(point).x).toBeCloseTo(target[i].x);
      expect(transform(point).y).toBeCloseTo(target[i].y);
    });
  });
  it("rejects coincident corners", () =>
    expect(() =>
      project(
        [
          { x: 0, y: 0 },
          { x: 0, y: 0 },
          { x: 0, y: 0 },
          { x: 0, y: 0 },
        ],
        referenceCorners,
      ),
    ).toThrow());
  it("covers every question and student ID digit", () => {
    expect(
      Array.from({ length: 50 }, (_, i) => answerCenters(i + 1)).flat(),
    ).toHaveLength(200);
    expect(studentIdCenters(0)).toHaveLength(10);
  });
  it("reads independent ink positions and preserves unfilled questions", () => {
    const width = 1040,
      height = 1848,
      data = new Uint8ClampedArray(width * height * 4).fill(255);
    for (let y = 584; y <= 602; y++)
      for (let x = 211; x <= 229; x++)
        if (Math.hypot(x - 220, y - 593) < 9) {
          const i = (y * width + x) * 4;
          data[i] = data[i + 1] = data[i + 2] = 0;
        }
    const result = readSheet(
      { width, height, data } as ImageData,
      referenceCorners,
    );
    expect(result.answers[0].value).toBe("A");
    expect(result.answers.slice(1).every((a) => a.status === "blank")).toBe(
      true,
    );
  });
});
