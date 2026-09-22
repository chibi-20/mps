import { useEffect, useRef, useState } from "react";
import type { Field } from "../types";
import { renderPage, type ScanSource } from "../services/ocr";
import {
  answerCenters,
  detectCorners,
  project,
  readSheet,
  referenceCorners,
  studentIdCenters,
  type Corners,
  type Point,
} from "../services/omr";
const cornerNames = ["top-left", "top-right", "bottom-right", "bottom-left"];
export default function AnswerSheetScanner({
  source,
  onResult,
}: {
  source: ScanSource;
  onResult: (fields: Field[]) => void;
}) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const [corners, setCorners] = useState<Corners | null>(null);
  const [next, setNext] = useState<number | null>(null);
  const [size, setSize] = useState({ width: 1, height: 1 });
  const [error, setError] = useState("");
  const [ready, setReady] = useState(false);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    let active = true;
    async function load() {
      try {
        const c = canvas.current!;
        if (source.pdf) {
          if (source.pageCount !== 1)
            throw new Error("For answer sheets, upload one page at a time.");
          await renderPage(source, 1, c, 2);
        } else {
          const image = new Image();
          image.src = source.url;
          await image.decode();
          const scale = Math.min(1, 1600 / image.width);
          c.width = image.width * scale;
          c.height = image.height * scale;
          c.getContext("2d")!.drawImage(image, 0, 0, c.width, c.height);
        }
        if (!active) return;
        setSize({ width: c.width, height: c.height });
        const found = detectCorners(
          c.getContext("2d")!.getImageData(0, 0, c.width, c.height),
        );
        setCorners(found);
        setNext(found ? null : 0);
        setReady(true);
      } catch (e) {
        if (active) setError((e as Error).message);
      }
    }
    void load();
    return () => {
      active = false;
    };
  }, [source]);
  function choose(point: Point) {
    if (next === null) return;
    setCorners((old) => {
      const updated = old
        ? ([...old] as Corners)
        : ([point, point, point, point] as Corners);
      updated[next] = point;
      return updated;
    });
    setNext(next === 3 ? null : next + 1);
  }
  let overlay: Point[] = [];
  try {
    if (corners && next === null) {
      const map = project(referenceCorners, corners);
      overlay = [
        ...Array.from({ length: 50 }, (_, i) => answerCenters(i + 1)).flat(),
        ...Array.from({ length: 5 }, (_, i) => studentIdCenters(i)).flat(),
      ].map(map);
    }
  } catch {
    /* Incomplete corner selection is not a valid projection yet. */
  }
  function scan() {
    if (!corners || !canvas.current || busy) return;
    setBusy(true);
    setError("");
    try {
      const result = readSheet(
        canvas.current
          .getContext("2d")!
          .getImageData(0, 0, size.width, size.height),
        corners,
      );
      const fields: Field[] = [
        "Name",
        "Term",
        "Grade & Section",
        "Teacher",
        "Score",
      ].map((label, i) => ({
        key: ["name", "term", "gradeSection", "teacher", "score"][i],
        label: `${label} (enter manually)`,
        value: "",
        confidence: 0,
        page: 1,
        status: "unreviewed",
      }));
      result.answers.forEach((answer, i) =>
        fields.push({
          key: `question${i + 1}`,
          label: `Question ${i + 1}`,
          value: answer.value,
          confidence: answer.confidence,
          page: 1,
          status: answer.status,
        }),
      );
      result.studentId.forEach((digit, i) =>
        fields.push({
          key: `studentIdDigit${i + 1}`,
          label: `Student ID digit ${i + 1}`,
          value: digit.value,
          confidence: digit.confidence,
          page: 1,
          status: digit.status,
        }),
      );
      onResult(fields);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="sheet-scanner">
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      <p>
        Use the matching 50-question A–D sheet. Keep all four outer black
        squares visible.
      </p>
      {ready && (
        <>
          <p>
            {next === null
              ? "Check that the green dots sit inside the bubbles. Adjust the corners if needed."
              : `Select the ${cornerNames[next]} outer black square.`}
          </p>
          <button onClick={() => setNext(0)}>Adjust corners</button>
        </>
      )}
      <div
        className="sheet-alignment"
        onClick={(e) => {
          const rect = e.currentTarget.getBoundingClientRect();
          choose({
            x: ((e.clientX - rect.left) / rect.width) * size.width,
            y: ((e.clientY - rect.top) / rect.height) * size.height,
          });
        }}
      >
        <canvas ref={canvas} />
        <svg viewBox={`0 0 ${size.width} ${size.height}`} aria-hidden="true">
          {overlay.map((point, i) => (
            <circle
              key={i}
              cx={point.x}
              cy={point.y}
              r={(3 * size.width) / 1040}
              fill="#00a76f"
            />
          ))}
          {corners?.map((point, i) => (
            <g key={i}>
              <circle
                cx={point.x}
                cy={point.y}
                r={(15 * size.width) / 1040}
                fill="none"
                stroke="#e95b28"
                strokeWidth={(3 * size.width) / 1040}
              />
              <text
                x={point.x + (18 * size.width) / 1040}
                y={point.y}
                fontSize={(22 * size.width) / 1040}
                fill="#d53b14"
              >
                {i + 1}
              </text>
            </g>
          ))}
        </svg>
      </div>
      {ready && (
        <details>
          <summary>Set corners with keyboard</summary>
          {cornerNames.map((name, i) => (
            <div className="field-pair" key={name}>
              {(["x", "y"] as const).map((axis) => (
                <label key={axis}>
                  {name} {axis}
                  <input
                    type="number"
                    min={0}
                    max={axis === "x" ? size.width : size.height}
                    value={corners?.[i][axis] ?? 0}
                    onChange={(e) => {
                      const updated: Corners = corners
                        ? [...corners]
                        : [
                            { x: 0, y: 0 },
                            { x: size.width, y: 0 },
                            { x: size.width, y: size.height },
                            { x: 0, y: size.height },
                          ];
                      updated[i] = {
                        ...updated[i],
                        [axis]: Number(e.target.value),
                      };
                      setCorners(updated);
                      setNext(null);
                    }}
                  />
                </label>
              ))}
            </div>
          ))}
        </details>
      )}
      <button
        className="primary"
        disabled={
          !ready || !corners || next !== null || !overlay.length || busy
        }
        onClick={scan}
      >
        {busy ? "Reading marks…" : "Read answer sheet"}
      </button>
      <p>
        Review every answer. Enter header fields manually. No score is
        calculated.
      </p>
    </div>
  );
}
