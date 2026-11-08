import { useEffect, useMemo, useRef, useState } from "react";
import type { Field } from "../types";
import { renderPage, type ScanSource } from "../services/ocr";
import { getMpsAssessments, MpsApiError, submitMpsScan, type MpsAssessment, type MpsScan } from "../services/api";
import type { Corners } from "../services/omr";
import {
  findSheetCorners,
  mapFourSubjectBubble,
  mapFourSubjectTemplate,
  parseStudentAssessmentBarcode,
  parseSubjectBarcode,
  readFourSubjectAnswers,
  rectifyFourSubjectSheet,
  studentAssessmentBarcodeRegion,
  subjectBarcodeRegion,
  trackFourSubjectBoxes,
  trackFourSubjectBubbleCenters,
  type FourSubjectBubbleCenters,
  type BubbleAnswer,
} from "../services/fiveSubjectOmr";
import { RotateCcw, RotateCw } from "lucide-react";

const columnTitles = ["Subject 1", "Subject 2", "Subject 3", "Subject 4"];
const letter = (n: number) => String.fromCharCode(65 + n);

function fieldValue(fields: Field[], key: string) {
  return fields.find((field) => field.key === key)?.value.trim() ?? "";
}

function questionKey(column: number, item: number) {
  return `subject${column + 1}Question${item}`;
}

function makeField(key: string, label: string, value: string, status: Field["status"], confidence = 0.9): Field {
  return { key, label, value, status, confidence, page: 1 };
}

export default function AnswerSheetScanner({
  source,
  reviewFields,
  onResult,
  apiBaseUrl,
  authToken,
  teacherId,
  teacherDisplay,
  onSessionExpired,
}: {
  source: ScanSource;
  reviewFields: Field[];
  onResult: (fields: Field[]) => void;
  apiBaseUrl: string;
  authToken: string;
  teacherId: number;
  teacherDisplay: string;
  onSessionExpired: () => void;
}) {
  const canvas = useRef<HTMLCanvasElement>(null);
  const lock = useRef(false);
  const submissionIds = useRef(new Map<string, { signature: string; id: string }>());
  const [corners, setCorners] = useState<[{ x: number; y: number }, { x: number; y: number }, { x: number; y: number }, { x: number; y: number }] | null>(null);
  const [subjectBoxes, setSubjectBoxes] = useState<Corners[] | null>(null);
  const [bubbleCenters, setBubbleCenters] = useState<FourSubjectBubbleCenters | null>(null);
  const [nextCorner, setNextCorner] = useState<number | null>(null);
  const [size, setSize] = useState({ width: 1, height: 1 });
  const [ready, setReady] = useState(false);
  const [preparing, setPreparing] = useState(false);
  const [prepareAttempt, setPrepareAttempt] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [note, setNote] = useState("");
  const [answers, setAnswers] = useState<BubbleAnswer[][] | null>(null);
  const scopeKey = `${apiBaseUrl}:${teacherId}`;
  const assessmentCacheKey = `mps-scanner-assessments:${scopeKey}`;
  const outboxKey = `mps-scanner-outbox:${scopeKey}`;
  const [connected, setConnected] = useState(false);
  const [assessments, setAssessments] = useState<MpsAssessment[]>(() => { try { return JSON.parse(localStorage.getItem(assessmentCacheKey) || "[]") as MpsAssessment[]; } catch { return []; } });
  const [outbox, setOutbox] = useState<MpsScan[]>(() => { try { return JSON.parse(localStorage.getItem(outboxKey) || "[]") as MpsScan[]; } catch { return []; } });
  const [mapping, setMapping] = useState<Record<string, number[]>>({});
  const [submitting, setSubmitting] = useState(false);
  const [submitReport, setSubmitReport] = useState("");

  useEffect(() => {
    localStorage.setItem(outboxKey, JSON.stringify(outbox));
    window.dispatchEvent(new CustomEvent("mps-outbox-change", { detail: { key: outboxKey, scans: outbox } }));
  }, [outbox, outboxKey]);
  useEffect(() => {
    const update = (event: Event) => { const detail = (event as CustomEvent<{ key: string; scans: MpsScan[] }>).detail; if (detail?.key === outboxKey) setOutbox(detail.scans); };
    window.addEventListener("mps-outbox-change", update);
    return () => window.removeEventListener("mps-outbox-change", update);
  }, [outboxKey]);
  useEffect(() => {
    let active = true;
    getMpsAssessments(apiBaseUrl, authToken).then((rows) => {
      if (!active) return;
      setAssessments(rows); localStorage.setItem(assessmentCacheKey, JSON.stringify(rows)); setConnected(true);
    }).catch((e) => {
      if (!active) return;
      setConnected(assessments.length > 0);
      if (e instanceof MpsApiError && e.status === 401) onSessionExpired();
      else if (!assessments.length) setError(`Could not load assessment list. Connect to the network first: ${(e as Error).message}`);
    });
    return () => { active = false; };
  }, [apiBaseUrl, authToken, assessmentCacheKey, onSessionExpired]);

  useEffect(() => {
    let active = true;
    async function prepare() {
      setPreparing(true);
      try {
        const c = canvas.current;
        if (!c) return;
        if (source.pdf) {
          if (source.pageCount !== 1) throw new Error("For this sheet, upload one page at a time.");
          await renderPage(source, 1, c, 1.6);
        } else {
          const image = new Image();
          image.src = source.url;
          await image.decode();
          const scale = Math.min(1, 1600 / image.width);
          c.width = Math.round(image.width * scale);
          c.height = Math.round(image.height * scale);
          c.getContext("2d")!.drawImage(image, 0, 0, c.width, c.height);
        }
        if (!active) return;
        setSize({ width: c.width, height: c.height });
        setCorners(findSheetCorners(c.getContext("2d", { willReadFrequently: true })!.getImageData(0, 0, c.width, c.height)));
        setSubjectBoxes(null);
        setBubbleCenters(null);
        setReady(true);
        setAnswers(null);
        setMapping({});
        setSubmitReport("");
        onResult([]);
      } catch (e) {
        if (active) setError((e as Error).message);
      } finally {
        if (active) setPreparing(false);
      }
    }
    setReady(false);
    setError("");
    void prepare();
    return () => { active = false; };
  }, [source, prepareAttempt]);

  // Keep the alignment preview anchored to each printed subject box before
  // the user starts a read. The green dots should show the same local bubble
  // tracking that the OMR reader will use, rather than only the page-wide warp.
  useEffect(() => {
    if (!ready || !corners || nextCorner !== null || !canvas.current) return;
    const c = canvas.current;
    try {
      const photo = c.getContext("2d", { willReadFrequently: true })!.getImageData(0, 0, size.width, size.height);
      const straight = rectifyFourSubjectSheet(photo, corners);
      const trackedBoxes = trackFourSubjectBoxes(straight);
      const trackedBubbles = trackFourSubjectBubbleCenters(straight, trackedBoxes);
      setSubjectBoxes(trackedBoxes);
      setBubbleCenters(trackedBubbles);
    } catch {
      setSubjectBoxes(null);
      setBubbleCenters(null);
    }
  }, [ready, corners, nextCorner, size.width, size.height]);

  const dots = useMemo(() => {
    if (!corners || nextCorner !== null) return [];
    try {
      const map = mapFourSubjectTemplate(corners);
      return Array.from({ length: 4 * 50 * 4 }, (_, i) => {
        const col = Math.floor(i / 200);
        const item = Math.floor((i % 200) / 4);
        const choice = i % 4;
        return map(mapFourSubjectBubble(col, item, choice, subjectBoxes ?? undefined, bubbleCenters ?? undefined));
      });
    } catch { return []; }
  }, [corners, nextCorner, subjectBoxes, bubbleCenters]);

  function chooseCorner(point: { x: number; y: number }) {
    if (nextCorner === null) return;
    setCorners((old) => {
      const selected = old ? [...old] : [point, point, point, point];
      selected[nextCorner] = point;
      return selected as typeof corners;
    });
    setNextCorner(nextCorner === 3 ? null : nextCorner + 1);
  }

  function rotateSheet(direction: -1 | 1) {
    const sourceCanvas = canvas.current;
    if (!sourceCanvas || busy || nextCorner !== null) return;
    const rotated = document.createElement("canvas");
    rotated.width = sourceCanvas.height;
    rotated.height = sourceCanvas.width;
    const context = rotated.getContext("2d", { willReadFrequently: true });
    if (!context) return;
    context.translate(rotated.width / 2, rotated.height / 2);
    context.rotate(direction * Math.PI / 2);
    context.drawImage(sourceCanvas, -sourceCanvas.width / 2, -sourceCanvas.height / 2);
    sourceCanvas.width = rotated.width;
    sourceCanvas.height = rotated.height;
    sourceCanvas.getContext("2d", { willReadFrequently: true })!.drawImage(rotated, 0, 0);
    const nextSize = { width: rotated.width, height: rotated.height };
    setSize(nextSize);
    setCorners(findSheetCorners(sourceCanvas.getContext("2d", { willReadFrequently: true })!.getImageData(0, 0, nextSize.width, nextSize.height)));
    setSubjectBoxes(null);
    setBubbleCenters(null);
    setAnswers(null);
    setMapping({});
    setSubmitReport("");
    onResult([]);
  }

  async function syncOutbox() {
    if (!outbox.length || !navigator.onLine || submitting) return;
    setSubmitting(true); setError("");
    const pending = [...outbox];
    while (pending.length) {
      try { await submitMpsScan(apiBaseUrl, authToken, pending[0]); pending.shift(); setOutbox([...pending]); }
      catch (e) {
        if (e instanceof MpsApiError && e.status === 401) onSessionExpired();
        setError(`Sync paused with ${pending.length} scan${pending.length === 1 ? "" : "s"} still pending: ${(e as Error).message}`);
        break;
      }
    }
    if (!pending.length) setNote("All queued scans synced to MPS.");
    setSubmitting(false);
  }

  async function scan() {
    if (!corners || !canvas.current || busy || nextCorner !== null) return;
    setBusy(true); setError(""); setNote(""); setSubmitReport("");
    try {
      const photo = canvas.current.getContext("2d", { willReadFrequently: true })!.getImageData(0, 0, size.width, size.height);
      const straight = rectifyFourSubjectSheet(photo, corners);
      const trackedBoxes = trackFourSubjectBoxes(straight);
      const trackedBubbles = trackFourSubjectBubbleCenters(straight, trackedBoxes);
      const readAnswers = readFourSubjectAnswers(straight, trackedBoxes, trackedBubbles);
      const { createBarcodeReader, decodeBarcode } = await import("../services/barcode");
      const reader = createBarcodeReader();
      const studentInfo = parseStudentAssessmentBarcode(decodeBarcode(reader, straight, studentAssessmentBarcodeRegion));
      const assessmentId = studentInfo.assessmentId;
      const subjectInfos = Array.from({ length: 4 }, (_, column) =>
        parseSubjectBarcode(decodeBarcode(reader, straight, subjectBarcodeRegion(column))),
      );
      const sectionId = subjectInfos.find((subject) => subject.sectionId)?.sectionId ?? "";
      const nextFields: Field[] = [
        makeField("studentId", "Student ID (from barcode)", studentInfo.studentId, studentInfo.studentId ? "marked" : "unreviewed", studentInfo.studentId ? 0.99 : 0),
        makeField("sectionId", "Section ID (from subject barcode)", sectionId, sectionId ? "marked" : "unreviewed", sectionId ? 0.99 : 0),
        makeField("assessmentId", "Assessment ID (from barcode)", assessmentId, assessmentId ? "marked" : "unreviewed", assessmentId ? 0.99 : 0),
      ];
      const preselected: Record<string, number[]> = {};
      for (let column = 0; column < 4; column++) {
        const subjectInfo = subjectInfos[column];
        nextFields.push(makeField(`subject${column + 1}Id`, `${columnTitles[column]} ID`, subjectInfo.subjectId, subjectInfo.subjectId ? "marked" : "unreviewed", subjectInfo.subjectId ? 0.99 : 0));
        const subjectStudentId = subjectInfo.studentId || studentInfo.studentId;
        nextFields.push(makeField(`subject${column + 1}StudentId`, `${columnTitles[column]} student ID`, subjectStudentId, subjectStudentId ? "marked" : "unreviewed", subjectStudentId ? 0.99 : 0));
        if (assessmentId && subjectInfo.subjectId) {
          const match = assessments.find((item) => String(item.assessment_id) === assessmentId && String(item.subject_id) === subjectInfo.subjectId && String(item.section_id) === sectionId);
          if (match) preselected[String(column)] = [match.assessment_id];
        }
        readAnswers[column].forEach((answer, i) => {
          nextFields.push(makeField(questionKey(column, i + 1), `${columnTitles[column]} · Question ${i + 1}`, answer.choice ?? "", answer.status, answer.confidence));
        });
      }
      setAnswers(readAnswers);
      setSubjectBoxes(trackedBoxes);
      setBubbleCenters(trackedBubbles);
      setMapping(preselected);
      submissionIds.current.clear();
      onResult(nextFields);
      if (!studentInfo.studentId || !sectionId) setNote("One or more barcodes could not be read. Enter missing IDs in the review panel, then confirm the answers.");
      else if (!assessmentId) setNote("Student sheet read. Could not read the assessment barcode, so map the assessment manually.");
      else if (!Object.keys(preselected).length) setNote(`Student and assessment IDs read. Assessment ${assessmentId} could not be matched to a subject column; map it manually.`);
      else setNote(`Sheet read. Assessment ${assessmentId} was matched automatically; review answers and barcode values before sending to MPS.`);
    } catch (e) {
      setError((e as Error).message);
    } finally { setBusy(false); }
  }

  const studentId = fieldValue(reviewFields, "studentId");
  const sectionId = fieldValue(reviewFields, "sectionId");
  const scanned = answers !== null;
  const mappedRows = Array.from({ length: 4 }, (_, column) => {
    const subjectId = fieldValue(reviewFields, `subject${column + 1}Id`);
    const barcodeStudentId = fieldValue(reviewFields, `subject${column + 1}StudentId`);
    const options = assessments.filter((item) => String(item.subject_id) === subjectId && String(item.section_id) === sectionId);
    const selected = (mapping[String(column)] || [])
      .map((id) => assessments.find((item) => item.assessment_id === id))
      .filter((item): item is MpsAssessment => !!item);
    const mappedItems = selected.reduce((sum, item) => sum + Number(item.total_items), 0);
    return { column, subjectId, barcodeStudentId, options, selected, mappedItems };
  });
  const configuredRows = mappedRows.filter(({ selected }) => selected.length > 0);
  const unresolved = configuredRows.filter(({ subjectId, barcodeStudentId, selected, options, mappedItems }) =>
    !subjectId || !studentId || !sectionId || barcodeStudentId !== studentId || !selected.length || selected.some((item) => !item.answer_key_ready) || !options.length || mappedItems > 50,
  );

  async function submit() {
    if (!connected || !answers || !studentId || !sectionId || !configuredRows.length || unresolved.length || submitting) return;
    setSubmitting(true); setError(""); setSubmitReport("");
    const reports: string[] = [];
    for (const row of mappedRows) {
      let itemOffset = 0;
      for (const assessment of row.selected) {
        const total = Number(assessment.total_items);
        const values = Array.from({ length: total }, (_, i) => fieldValue(reviewFields, questionKey(row.column, itemOffset + i + 1)));
        const bad = values.findIndex((value, i) => {
          const field = reviewFields.find((item) => item.key === questionKey(row.column, itemOffset + i + 1));
          return !field || field.status === "multiple" || field.status === "uncertain" || !["", "A", "B", "C", "D"].includes(value.toUpperCase());
        });
        if (bad !== -1) { reports.push(`${assessment.subject_name} · ${assessment.title}: review question ${itemOffset + bad + 1}`); itemOffset += total; continue; }
        const choices = values.map((value) => value ? value.toUpperCase() as "A" | "B" | "C" | "D" : null);
        const signature = JSON.stringify([assessment.assessment_id, sectionId, studentId, choices]);
        const submitKey = `${row.column}:${assessment.assessment_id}`;
        let id = submissionIds.current.get(submitKey);
        if (!id || id.signature !== signature) {
          id = { signature, id: crypto.randomUUID() };
          submissionIds.current.set(submitKey, id);
        }
        try {
          const scan: MpsScan = {
            submission_id: id.id,
            assessment_id: Number(assessment.assessment_id),
            section_id: Number(sectionId),
            student_id: studentId,
            answers: choices,
          };
          const result = await submitMpsScan(apiBaseUrl, authToken, scan);
          reports.push(`${assessment.subject_name} · ${assessment.title}: ${result.score}/${result.total_items}${result.duplicate ? " (already received)" : " sent"}`);
        } catch (e) {
          if (!(e instanceof MpsApiError) || e.status === 401 || e.status >= 500) {
            const scan: MpsScan = { submission_id: id!.id, assessment_id: Number(assessment.assessment_id), section_id: Number(sectionId), student_id: studentId, answers: choices };
            setOutbox((old) => old.some((item) => item.submission_id === scan.submission_id) ? old : [...old, scan]);
            if (e instanceof MpsApiError && e.status === 401) onSessionExpired();
            reports.push(`${assessment.subject_name} · ${assessment.title}: saved on this device; sync pending (${(e as Error).message})`);
          } else reports.push(`${assessment.subject_name} · ${assessment.title}: rejected by MPS (${(e as Error).message})`);
        }
        itemOffset += total;
      }
    }
    setSubmitReport(reports.join(" · "));
    setSubmitting(false);
  }

  return (
    <div className="sheet-scanner">
      <section className="mps-connect">
        <div className="section-title"><strong>MPS scan sync</strong><span className={connected ? "connection-state connected" : "connection-state"}>{navigator.onLine && connected ? "Connected" : connected ? "Offline · cached assessments" : "Loading assessments…"}</span></div>
        <p>Signed in as {teacherDisplay}. Scans are saved on this device when offline and uploaded when you sync.</p>
        <div className="sync-row"><strong>{outbox.length} scan{outbox.length === 1 ? "" : "s"} not synced</strong><button onClick={() => void syncOutbox()} disabled={!outbox.length || !navigator.onLine || submitting}>{submitting ? "Syncing…" : "Sync now"}</button></div>
      </section>
      {error && <p role="alert" className="error">{error}</p>}
      {note && <p role="status" className="sheet-note">{note}</p>}
      <p>Use the MPS four-subject template at 100% print scale. The top barcode contains <code>assessmentId_studentId</code>; each subject box contains <code>subjectId_sectionId</code>. Keep the whole page visible and use a dark pencil or pen for marks.</p>
      {ready && <p>{nextCorner === null ? scanned ? "Each box uses its four detected corners to align the 4 × 50 answer grid. Review the blue corner points and green bubble markers." : "Blue points show the four detected corners in each subject box; green dots show its 4 × 50 answer grid. If a blue point is misplaced, reset and select the four outer page markers." : `Tap the ${["top-left", "top-right", "bottom-right", "bottom-left"][nextCorner]} black registration square.`}</p>}
      <div className="sheet-alignment" hidden={!ready} onClick={(e) => {
          if (nextCorner === null) return;
          const rect = e.currentTarget.getBoundingClientRect();
          chooseCorner({ x: ((e.clientX - rect.left) / rect.width) * size.width, y: ((e.clientY - rect.top) / rect.height) * size.height });
        }}>
          <canvas ref={canvas} />
          <svg viewBox={`0 0 ${size.width} ${size.height}`} aria-hidden="true">
            {subjectBoxes?.map((box, i) => {
              const map = mapFourSubjectTemplate(corners!);
              const points = box.map((point) => map(point));
              return <g key={`subject-box-${i}`}>
                <polygon points={points.map((point) => `${point.x},${point.y}`).join(" ")} fill="none" stroke="#287c68" strokeWidth={(4 * size.width) / 1040} strokeDasharray={`${12 * size.width / 1040} ${7 * size.width / 1040}`} />
                {points.map((point, corner) => <circle key={`subject-box-${i}-corner-${corner}`} cx={point.x} cy={point.y} r={(7 * size.width) / 1040} fill="#2367d1" stroke="white" strokeWidth={(2 * size.width) / 1040} />)}
              </g>;
            })}
            {dots.map((point, i) => <circle key={i} cx={point.x} cy={point.y} r={(3 * size.width) / 1040} fill="#00a76f" />)}
            {corners?.map((point, i) => <g key={i}><circle cx={point.x} cy={point.y} r={(15 * size.width) / 1040} fill="none" stroke="#e95b28" strokeWidth={(3 * size.width) / 1040} /><text x={point.x + (18 * size.width) / 1040} y={point.y} fontSize={(22 * size.width) / 1040} fill="#d53b14">{i + 1}</text></g>)}
          </svg>
        </div>
      {ready && <>
        <div className="sheet-actions">
          <button type="button" onClick={() => rotateSheet(-1)} disabled={busy || nextCorner !== null} aria-label="Rotate image counterclockwise"><RotateCcw size={16} /> Rotate left</button>
          <button type="button" onClick={() => rotateSheet(1)} disabled={busy || nextCorner !== null} aria-label="Rotate image clockwise"><RotateCw size={16} /> Rotate right</button>
          <button onClick={() => { setNextCorner(0); setCorners(null); setSubjectBoxes(null); setBubbleCenters(null); }} disabled={busy}>Set corners manually</button>
          <button className="primary" onClick={scan} disabled={!corners || busy || nextCorner !== null}>{busy ? "Reading sheet…" : scanned ? "Read sheet again" : "Read four-subject sheet"}</button>
        </div>
      </>}
      {!ready && <button className="primary" onClick={() => setPrepareAttempt((attempt) => attempt + 1)} disabled={preparing}>
        {preparing ? "Preparing answer sheet…" : error ? "Retry loading answer sheet" : "Prepare answer sheet"}
      </button>}
      {scanned && <section className="subject-mappings">
        <h3>Map subject columns to MPS assessments</h3>
        <p>Add every assessment for each subject, then order them top to bottom to match the answer-sheet question order. Combined subject items must fit within its 50 bubbles. Every assessment needs a complete MPS answer key.</p>
        {mappedRows.map(({ column, subjectId, barcodeStudentId, options, selected, mappedItems }) => (
          <label className="subject-mapping" key={column}>
            <span><strong>{columnTitles[column]}</strong><small>Subject ID {subjectId || "not read"}{barcodeStudentId && barcodeStudentId !== studentId ? " · barcode student ID does not match" : ""}</small></span>
            <div className="subject-assessment-list">
              {selected.map((item, order) => {
                const first = selected.slice(0, order).reduce((sum, value) => sum + Number(value.total_items), 0) + 1;
                const last = first + Number(item.total_items) - 1;
                return <div className="mapped-assessment" key={item.assessment_id}>
                  <span><strong>{item.title}</strong><small>Questions {first}–{last} · {item.total_items} items · {item.answer_key_ready ? "key ready" : "key missing"}</small></span>
                  <button type="button" aria-label={`Move ${item.title} up`} disabled={order === 0} onClick={() => setMapping((old) => { const ids = [...(old[String(column)] || [])]; [ids[order - 1], ids[order]] = [ids[order], ids[order - 1]]; return { ...old, [String(column)]: ids }; })}>↑</button>
                  <button type="button" aria-label={`Move ${item.title} down`} disabled={order === selected.length - 1} onClick={() => setMapping((old) => { const ids = [...(old[String(column)] || [])]; [ids[order + 1], ids[order]] = [ids[order], ids[order + 1]]; return { ...old, [String(column)]: ids }; })}>↓</button>
                  <button type="button" aria-label={`Remove ${item.title}`} onClick={() => setMapping((old) => ({ ...old, [String(column)]: (old[String(column)] || []).filter((id) => id !== item.assessment_id) }))}>×</button>
                </div>;
              })}
              <select value="" disabled={!connected || mappedItems >= 50} onChange={(e) => {
                const id = Number(e.target.value);
                if (!id) return;
                setMapping((old) => ({ ...old, [String(column)]: [...(old[String(column)] || []), id] }));
              }}>
                <option value="">{!subjectId ? "Subject barcode missing" : options.length ? "Add an assessment…" : "No matching assessment"}</option>
                {options.filter((item) => !mapping[String(column)]?.includes(item.assessment_id)).map((item) => <option key={item.assessment_id} value={item.assessment_id} disabled={!item.answer_key_ready || mappedItems + Number(item.total_items) > 50}>{item.title} · {item.total_items} items{item.answer_key_ready ? "" : " · key missing"}{mappedItems + Number(item.total_items) > 50 ? " · over 50 total" : ""}</option>)}
              </select>
              {mappedItems > 50 && <small>A subject column can hold at most 50 combined assessment items.</small>}
            </div>
          </label>
        ))}
        <button className="primary submit-sheet" onClick={() => void submit()} disabled={!connected || !studentId || !sectionId || !configuredRows.length || unresolved.length > 0 || submitting}>
          {submitting ? "Sending results…" : "Submit mapped answers to MPS"}
        </button>
        {submitReport && <p role="status" className="sheet-note">{submitReport}</p>}
        {(unresolved.length > 0 || !configuredRows.length) && <p className="subtle">Check the student and section IDs, match each used subject barcode to the student, and select at least one assessment with a complete answer key. Unused subject columns may stay blank.</p>}
      </section>}
    </div>
  );
}
