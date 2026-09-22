export interface Point {
  x: number;
  y: number;
}
export type Corners = [Point, Point, Point, Point];
export interface BubbleResult {
  value: string;
  status: "marked" | "blank" | "multiple" | "uncertain";
  confidence: number;
  scores: number[];
}
export const referenceCorners: Corners = [
  { x: 134, y: 414 },
  { x: 882, y: 440 },
  { x: 884, y: 1540 },
  { x: 64, y: 1518 },
];
export function project(from: Corners, to: Corners): (p: Point) => Point {
  const rows: number[][] = [];
  for (let i = 0; i < 4; i++) {
    const { x, y } = from[i];
    const { x: u, y: v } = to[i];
    rows.push(
      [x, y, 1, 0, 0, 0, -u * x, -u * y, u],
      [0, 0, 0, x, y, 1, -v * x, -v * y, v],
    );
  }
  for (let i = 0; i < 8; i++) {
    let pivot = i;
    for (let j = i + 1; j < 8; j++)
      if (Math.abs(rows[j][i]) > Math.abs(rows[pivot][i])) pivot = j;
    [rows[i], rows[pivot]] = [rows[pivot], rows[i]];
    const divisor = rows[i][i];
    if (Math.abs(divisor) < 1e-10)
      throw new Error("Select four different corner markers around the sheet.");
    for (let k = i; k < 9; k++) rows[i][k] /= divisor;
    for (let j = 0; j < 8; j++)
      if (j !== i) {
        const factor = rows[j][i];
        for (let k = i; k < 9; k++) rows[j][k] -= factor * rows[i][k];
      }
  }
  const h = rows.map((row) => row[8]);
  return ({ x, y }) => {
    const d = h[6] * x + h[7] * y + 1;
    return {
      x: (h[0] * x + h[1] * y + h[2]) / d,
      y: (h[3] * x + h[4] * y + h[5]) / d,
    };
  };
}
export function answerCenters(question: number): Point[] {
  let row: number,
    x: number,
    y: number,
    dx: number,
    dy: number,
    spacing: number;
  if (question <= 9) {
    row = question - 1;
    x = 220;
    y = 593;
    dx = -2.5;
    dy = 44.2;
    spacing = 32.3;
  } else if (question <= 19) {
    row = question - 10;
    x = 194;
    y = 1043;
    dx = -2.6;
    dy = 48;
    spacing = 33.5;
  } else if (question <= 28) {
    row = question - 20;
    x = 389;
    y = 600;
    dx = -1.65;
    dy = 44.2;
    spacing = 32.5;
  } else if (question <= 38) {
    row = question - 29;
    x = 370;
    y = 1048;
    dx = -1.8;
    dy = 48.1;
    spacing = 33.5;
  } else if (question <= 47) {
    row = question - 39;
    x = 557;
    y = 604;
    dx = -0.9;
    dy = 44.3;
    spacing = 32.5;
  } else {
    row = question - 48;
    x = 727;
    y = 608;
    dx = 0;
    dy = 43.8;
    spacing = 32.3;
  }
  return Array.from({ length: 4 }, (_, i) => ({
    x: x + row * dx + i * spacing,
    y: y + row * dy + i * 1.1,
  }));
}
export function studentIdCenters(column: number): Point[] {
  return Array.from({ length: 10 }, (_, digit) => ({
    x: 684 + column * 34.2 - digit * 0.8,
    y: 1108 + digit * 43.5 + column * 1.2,
  }));
}
function gray(data: Uint8ClampedArray, index: number) {
  return (
    data[index] * 0.299 + data[index + 1] * 0.587 + data[index + 2] * 0.114
  );
}
export function bubbleInk(
  image: ImageData,
  center: Point,
  radius: number,
): number {
  let dark = 0,
    count = 0;
  const samples: number[] = [];
  for (let dy = -radius * 1.9; dy <= radius * 1.9; dy++)
    for (let dx = -radius * 1.9; dx <= radius * 1.9; dx++) {
      const distance = Math.hypot(dx, dy);
      if (distance > radius * 1.9) continue;
      const x = Math.round(center.x + dx),
        y = Math.round(center.y + dy);
      if (x < 0 || y < 0 || x >= image.width || y >= image.height) continue;
      if (distance > radius * 1.55)
        samples.push(gray(image.data, (y * image.width + x) * 4));
    }
  samples.sort((a, b) => a - b);
  const paper = samples[Math.floor(samples.length * 0.8)] ?? 255;
  const threshold = paper * 0.68;
  for (let dy = -Math.ceil(radius); dy <= radius; dy++)
    for (let dx = -Math.ceil(radius); dx <= radius; dx++) {
      if (Math.hypot(dx, dy) > radius) continue;
      const x = Math.round(center.x + dx),
        y = Math.round(center.y + dy);
      if (x < 0 || y < 0 || x >= image.width || y >= image.height) continue;
      count++;
      if (gray(image.data, (y * image.width + x) * 4) < threshold) dark++;
    }
  return count ? dark / count : 0;
}
export function classifyBubbles(
  scores: number[],
  labels: string[],
): BubbleResult {
  const marked = scores
    .map((score, i) => ({ score, i }))
    .filter((x) => x.score >= 0.57);
  const max = Math.max(...scores);
  const sorted = [...scores].sort((a, b) => b - a);
  if (marked.length > 1)
    return {
      value: marked.map((x) => labels[x.i]).join(", "),
      status: "multiple",
      confidence: 0.25,
      scores,
    };
  if (marked.length === 1 && sorted[0] - sorted[1] >= 0.2)
    return {
      value: labels[marked[0].i],
      status: "marked",
      confidence: Math.min(0.97, 0.65 + (sorted[0] - sorted[1]) * 0.3),
      scores,
    };
  if (max > 0.36)
    return { value: "", status: "uncertain", confidence: 0.2, scores };
  return { value: "", status: "blank", confidence: 0.5, scores };
}
export function readSheet(image: ImageData, corners: Corners) {
  const map = project(referenceCorners, corners);
  const scale =
    Math.hypot(corners[1].x - corners[0].x, corners[1].y - corners[0].y) / 748;
  const radius = 8 * scale;
  const read = (centers: Point[], labels: string[]) =>
    classifyBubbles(
      centers.map((p) => bubbleInk(image, map(p), radius)),
      labels,
    );
  return {
    answers: Array.from({ length: 50 }, (_, i) =>
      read(answerCenters(i + 1), ["A", "B", "C", "D"]),
    ),
    studentId: Array.from({ length: 5 }, (_, i) =>
      read(studentIdCenters(i), [
        "0",
        "1",
        "2",
        "3",
        "4",
        "5",
        "6",
        "7",
        "8",
        "9",
      ]),
    ),
  };
}
export function detectCorners(image: ImageData): Corners | null {
  const { width, height, data } = image;
  const visited = new Uint8Array(width * height);
  const candidates: Point[] = [];
  const minSide = Math.max(7, width * 0.009),
    maxSide = width * 0.045;
  for (let start = 0; start < width * height; start++) {
    if (visited[start] || gray(data, start * 4) > 65) continue;
    const stack = [start];
    visited[start] = 1;
    let count = 0,
      minX = width,
      minY = height,
      maxX = 0,
      maxY = 0;
    while (stack.length) {
      const index = stack.pop()!;
      const x = index % width,
        y = Math.floor(index / width);
      count++;
      minX = Math.min(minX, x);
      maxX = Math.max(maxX, x);
      minY = Math.min(minY, y);
      maxY = Math.max(maxY, y);
      for (const next of [
        x > 0 ? index - 1 : -1,
        x + 1 < width ? index + 1 : -1,
        y > 0 ? index - width : -1,
        y + 1 < height ? index + width : -1,
      ])
        if (next >= 0 && !visited[next]) {
          visited[next] = 1;
          if (gray(data, next * 4) <= 65) stack.push(next);
        }
    }
    const w = maxX - minX + 1,
      h = maxY - minY + 1;
    if (
      w >= minSide &&
      h >= minSide &&
      w < maxSide &&
      h < maxSide &&
      w / h > 0.65 &&
      w / h < 1.5 &&
      count / (w * h) > 0.7
    )
      candidates.push({ x: (minX + maxX) / 2, y: (minY + maxY) / 2 });
  }
  if (candidates.length < 4) return null;
  const pick = (score: (p: Point) => number) =>
    candidates.reduce((best, p) => (score(p) > score(best) ? p : best));
  const corners: Corners = [
    pick((p) => -p.x - p.y),
    pick((p) => p.x - p.y),
    pick((p) => p.x + p.y),
    pick((p) => -p.x + p.y),
  ];
  if (
    new Set(corners).size < 4 ||
    corners[1].x - corners[0].x < width * 0.4 ||
    corners[3].y - corners[0].y < height * 0.35
  )
    return null;
  return corners;
}
