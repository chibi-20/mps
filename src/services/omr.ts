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
  // Do not use connected components here: a page-corner square can touch the
  // sheet border or a dark background, which turns it into one huge component.
  // Instead, score small solid-square windows near each image corner.
  const shortSide = Math.min(width, height);
  if (!width || !height || shortSide < 100) return null;

  const integralWidth = width + 1;
  const integral = new Uint32Array(integralWidth * (height + 1));
  for (let y = 0; y < height; y++) {
    let rowDark = 0;
    const rowOffset = y * width * 4;
    const integralRow = (y + 1) * integralWidth;
    const previousRow = y * integralWidth;
    for (let x = 0; x < width; x++) {
      if (gray(data, rowOffset + x * 4) < 85) rowDark++;
      integral[integralRow + x + 1] = integral[previousRow + x + 1] + rowDark;
    }
  }
  const darkCount = (left: number, top: number, side: number) => {
    const right = left + side;
    const bottom = top + side;
    return integral[bottom * integralWidth + right]
      - integral[top * integralWidth + right]
      - integral[bottom * integralWidth + left]
      + integral[top * integralWidth + left];
  };

  type SquareCandidate = Point & { density: number; side: number; priority: number };
  const regionSpecs = [
    { left: 0, top: 0, right: 0.46, bottom: 0.36, projection: (x: number, y: number) => 2 - x / width - y / height },
    { left: 0.54, top: 0, right: 1, bottom: 0.36, projection: (x: number, y: number) => x / width + 1 - y / height },
    { left: 0.54, top: 0.62, right: 1, bottom: 1, projection: (x: number, y: number) => x / width + y / height },
    { left: 0, top: 0.62, right: 0.46, bottom: 1, projection: (x: number, y: number) => 1 - x / width + y / height },
  ] as const;
  const step = Math.max(1, Math.round(shortSide / 900));
  const sizes = [...new Set([0.012, 0.014, 0.016, 0.018, 0.021, 0.024, 0.027].map((ratio) => Math.max(8, Math.round(shortSide * ratio))))];
  const detected = regionSpecs.map((region) => {
    const candidates: SquareCandidate[] = [];
    for (const side of sizes) {
      const half = Math.floor(side / 2);
      const left = Math.max(0, Math.ceil(region.left * width) + half);
      const right = Math.min(width - side, Math.floor(region.right * width) - half);
      const top = Math.max(0, Math.ceil(region.top * height) + half);
      const bottom = Math.min(height - side, Math.floor(region.bottom * height) - half);
      for (let y = top; y <= bottom; y += step) {
        for (let x = left; x <= right; x += step) {
          const density = darkCount(x, y, side) / (side * side);
          if (density < 0.82) continue;
          const center = { x: x + side / 2, y: y + side / 2 };
          candidates.push({ ...center, density, side, priority: density + region.projection(center.x, center.y) * 0.08 });
        }
      }
    }
    if (!candidates.length) return null;
    const best = candidates.reduce((current, candidate) => candidate.priority > current.priority ? candidate : current);
    // Dense windows overlap across the same printed square. Their centroid
    // estimates the marker center even when a border touches one side.
    const cluster = candidates.filter((candidate) =>
      candidate.side === best.side &&
      candidate.density >= best.density - 0.035 &&
      Math.hypot(candidate.x - best.x, candidate.y - best.y) <= best.side * 0.7,
    );
    const count = Math.max(1, cluster.length);
    return {
      x: cluster.reduce((sum, candidate) => sum + candidate.x, 0) / count,
      y: cluster.reduce((sum, candidate) => sum + candidate.y, 0) / count,
    };
  });
  const topLeft = detected[0];
  const topRight = detected[1];
  const bottomRight = detected[2];
  const bottomLeft = detected[3];
  if (!topLeft || !topRight || !bottomRight || !bottomLeft) return null;
  const corners: Corners = [topLeft, topRight, bottomRight, bottomLeft];
  const distinct = corners.every((point, i) =>
    corners.slice(i + 1).every((other) => Math.hypot(point.x - other.x, point.y - other.y) > shortSide * 0.2),
  );
  if (
    !distinct ||
    topRight.x - topLeft.x < width * 0.4 ||
    bottomLeft.y - topLeft.y < height * 0.35 ||
    bottomRight.x < bottomLeft.x ||
    bottomRight.y < topRight.y
  )
    return null;
  return [topLeft, topRight, bottomRight, bottomLeft];
}
