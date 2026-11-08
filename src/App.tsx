import { useCallback, useEffect, useRef, useState } from "react";
import { Camera, Check, ChevronRight, FileText, ScanLine, Trash2, Upload, WifiOff } from "lucide-react";
import type { Field } from "./types";
import { closeDocument, openDocument, type ScanSource } from "./services/ocr";
import { maxFileMb, validateFile } from "./services/files";
import { logoutMps, submitMpsScan, type MpsScan, type ScannerSession } from "./services/api";
import CameraCapture from "./components/CameraCapture";
import InstallButton from "./components/InstallButton";
import AnswerSheetScanner from "./components/AnswerSheetScanner";
import ScannerSignIn from "./components/ScannerSignIn";

export default function App() {
  const [mpsUrlConfig] = useState(() => {
    const fromDashboard = new URLSearchParams(window.location.search).get("mps_url");
    if (fromDashboard) {
      localStorage.setItem("mps-suggested-url", fromDashboard);
      localStorage.setItem("mps-url-source", "teacher-dashboard");
      return { url: fromDashboard, fromDashboard: true };
    }
    const cached = localStorage.getItem("mps-suggested-url");
    return {
      url: cached || import.meta.env.VITE_API_BASE_URL || "",
      fromDashboard: localStorage.getItem("mps-url-source") === "teacher-dashboard" || !!cached,
    };
  });
  const [session, setSession] = useState<ScannerSession | null>(() => {
    try {
      const saved = localStorage.getItem("mps-scanner-session");
      if (!saved) return null;
      const parsed = JSON.parse(saved) as ScannerSession;
      return Date.parse(parsed.expiresAt) > Date.now() ? parsed : null;
    } catch { return null; }
  });
  const onSessionExpired = useCallback(() => {
    localStorage.removeItem("mps-scanner-session");
    setSession(null);
  }, []);
  const [source, setSource] = useState<ScanSource | null>(null);
  const sourceRef = useRef<ScanSource | null>(null);
  const [fields, setFields] = useState<Field[]>([]);
  const [scanned, setScanned] = useState(false);
  const [busy, setBusy] = useState(false);
  const [drag, setDrag] = useState(false);
  const [camera, setCamera] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [online, setOnline] = useState(navigator.onLine);
  const [pendingScans, setPendingScans] = useState<MpsScan[]>([]);
  const [syncBusy, setSyncBusy] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  const lock = useRef(false);
  const outboxKey = session ? `mps-scanner-outbox:${session.baseUrl}:${session.teacherId}` : "";

  useEffect(() => {
    const suppliedUrl = new URLSearchParams(window.location.search).get("mps_url");
    sessionStorage.removeItem("mps-scanner-token");
    sessionStorage.removeItem("mps-api-base");
    localStorage.removeItem("mps-scanner-token");
    localStorage.removeItem("mps-api-base");
    if (suppliedUrl) {
      const url = new URL(window.location.href);
      url.searchParams.delete("mps_url");
      window.history.replaceState({}, "", url.pathname + url.hash);
    }
    if (!session) return;
    const delay = Date.parse(session.expiresAt) - Date.now();
    if (delay <= 0) { onSessionExpired(); return; }
    const timer = window.setTimeout(onSessionExpired, delay);
    return () => window.clearTimeout(timer);
  }, [session, onSessionExpired]);

  useEffect(() => {
    if (!outboxKey) { setPendingScans([]); return; }
    try { setPendingScans(JSON.parse(localStorage.getItem(outboxKey) || "[]") as MpsScan[]); }
    catch { setPendingScans([]); }
    const update = (event: Event) => {
      const detail = (event as CustomEvent<{ key: string; scans: MpsScan[] }>).detail;
      if (detail?.key === outboxKey) setPendingScans(detail.scans);
    };
    window.addEventListener("mps-outbox-change", update);
    return () => window.removeEventListener("mps-outbox-change", update);
  }, [outboxKey]);

  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
      if (sourceRef.current) closeDocument(sourceRef.current);
    };
  }, []);

  async function loadSheet(file?: File) {
    if (!file || lock.current) return;
    lock.current = true;
    setBusy(true); setError(""); setNotice("");
    try {
      validateFile(file);
      const next = await openDocument(file);
      if (sourceRef.current) closeDocument(sourceRef.current);
      sourceRef.current = next;
      setSource(next); setFields([]); setScanned(false);
    } catch (e) { setError((e as Error).message); }
    finally { lock.current = false; setBusy(false); }
  }

  function removeSheet() {
    if (lock.current) return;
    if (sourceRef.current) closeDocument(sourceRef.current);
    sourceRef.current = null;
    setSource(null); setFields([]); setScanned(false); setError(""); setNotice("");
  }

  function editField(index: number, value: string) {
    setFields((old) => old.map((field, i) => i === index
      ? { ...field, value, status: field.status ? "reviewed" : field.status, confidence: 1 }
      : field));
  }

  const fieldIndex = (key: string) => fields.findIndex((field) => field.key === key);
  const reviewField = (key: string) => fields.find((field) => field.key === key);
  const answerChoices = ["A", "B", "C", "D"] as const;
  const subjectNames = ["Araling Panlipunan", "Math", "English", "Science", "Filipino"];

  async function signOut() {
    const current = session;
    localStorage.removeItem("mps-scanner-session");
    setSession(null);
    if (current) try { await logoutMps(current.baseUrl, current.token); } catch { /* local access is cleared when offline */ }
  }

  async function syncPending() {
    if (!session || !navigator.onLine || syncBusy || !pendingScans.length) return;
    setSyncBusy(true); setNotice("");
    const remaining = [...pendingScans];
    while (remaining.length) {
      try { await submitMpsScan(session.baseUrl, session.token, remaining[0]); remaining.shift(); }
      catch (e) {
        if ((e as { status?: number }).status === 401) onSessionExpired();
        setNotice(`Sync paused. ${remaining.length} scan${remaining.length === 1 ? " remains" : "s remain"} on this device: ${(e as Error).message}`);
        break;
      }
    }
    localStorage.setItem(outboxKey, JSON.stringify(remaining));
    window.dispatchEvent(new CustomEvent("mps-outbox-change", { detail: { key: outboxKey, scans: remaining } }));
    if (!remaining.length) setNotice("All queued scans synced to MPS.");
    setSyncBusy(false);
  }

  if (!session) return <>
    <header className="topbar"><a className="brand" href="./">MPS Scanner</a><InstallButton /></header>
    <ScannerSignIn suggestedUrl={mpsUrlConfig.url} onSignedIn={(value) => {
      localStorage.setItem("mps-scanner-session", JSON.stringify(value));
      setSession(value);
    }} />
  </>;

  return <>
    <header className="topbar">
      <a className="brand" href="./" aria-label="MPS Scanner home"><span className="brand-icon"><ScanLine size={23} /></span>MPS Scanner</a>
      <div className="header-right">
        <span className="scanner-teacher">{session.teacherDisplay}</span>
        <strong className="pending-count">{pendingScans.length} not synced</strong>
        <button className="text-button" onClick={() => void syncPending()} disabled={!pendingScans.length || !online || syncBusy}>{syncBusy ? "Syncing…" : "Sync now"}</button>
        <button className="text-button" onClick={() => void signOut()}>Sign out</button>
        <InstallButton />
      </div>
    </header>
    <main>
      <div className="intro"><div><h1>Scan student answer sheets</h1></div></div>
      <nav className="steps" aria-label="Scanning workflow">
        {["Scan", "Review and Submit"].map((label, index) => (
          <div key={label} className={(scanned ? 1 : 0) === index ? "active" : (scanned ? 1 : 0) > index ? "complete" : ""}>
            <span>{scanned && index === 0 ? <Check size={14} /> : index + 1}</span>{label}{index === 0 && <ChevronRight size={15} />}
          </div>
        ))}
      </nav>
      {!online && <div className="banner"><WifiOff size={17} /> You’re offline. Scanning works with cached assessments; queued scans sync when you reconnect.</div>}
      {error && <div className="error" role="alert">{error}</div>}
      {notice && <div className="banner" role="status">{notice}</div>}
      <div className={`workspace ${scanned ? "reviewing" : ""}`}>
        <section className="panel document-panel">
          <div className="panel-heading"><div><h2>{source ? "Scan answer sheet" : "Add an answer sheet"}</h2></div></div>
          {!source ? <div className={`dropzone ${drag ? "dragging" : ""}`}
            onDragOver={(event) => { event.preventDefault(); setDrag(true); }}
            onDragLeave={() => setDrag(false)}
            onDrop={(event) => { event.preventDefault(); setDrag(false); if (event.dataTransfer.files.length > 1) setError("Choose one answer sheet at a time."); else void loadSheet(event.dataTransfer.files[0]); }}>
            <div className="upload-art"><FileText size={36} /><span><ScanLine size={16} /></span></div>
            <p>Choose or drop a photo or PDF of the four-subject answer sheet.</p>
            <button className="primary" disabled={busy} onClick={() => input.current?.click()}><Upload size={17} /> Choose answer sheet</button>
            <span className="file-hint">PDF, JPG, PNG, WEBP · Up to {maxFileMb} MB</span>
            <div className="or"><span />or<span /></div>
            <button className="camera-button" disabled={busy} onClick={() => setCamera(true)}><Camera size={18} /> Use your camera</button>
          </div> : <>
            <div className="file-info"><FileText size={24} /><div><strong>{source.file.name}</strong><small>{(source.file.size / 1024 / 1024).toFixed(2)} MB · {source.pageCount} {source.pageCount === 1 ? "page" : "pages"}</small></div>
              <button aria-label="Remove answer sheet" disabled={busy} onClick={removeSheet}><Trash2 size={17} /></button>
            </div>
            <AnswerSheetScanner key={source.url} source={source} apiBaseUrl={session.baseUrl} authToken={session.token} teacherId={session.teacherId} teacherDisplay={session.teacherDisplay} onSessionExpired={onSessionExpired} reviewFields={fields} onResult={(nextFields) => { setFields(nextFields); setScanned(nextFields.length > 0); }} />
          </>}
          <input ref={input} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" hidden onChange={(event) => { void loadSheet(event.target.files?.[0]); event.target.value = ""; }} />
        </section>
        <section className="panel results-panel">
          <div className="panel-heading"><div><h2>Review and Submit</h2></div></div>
          {!scanned ? <div className="results-empty"><div className="result-illustration"><div><span /><i /></div><div><span /><i /></div><div><span /><i /></div><span className="check-bubble"><Check size={20} /></span></div><p>{source ? "Read the sheet to review detected answers and barcodes." : "Add an answer sheet, then scan it to begin review."}</p></div> : <div className="result-content">
            <div className="review-note">Review the IDs and answers against the sheet. For unclear or multiple marks, select the correct answer below. Leave a question unselected to submit it blank.</div>
            <div className="review-identifiers">
              {["studentId", "sectionId", "assessmentId"].map((key) => {
                const index = fieldIndex(key);
                const field = reviewField(key);
                if (index < 0 || !field) return null;
                return <label className="review-id-card" key={key}>
                  <strong>{field.label}</strong>
                  <input value={field.value} onChange={(event) => editField(index, event.target.value)} />
                  {field.status === "unreviewed" && <small>Barcode not read · enter manually</small>}
                </label>;
              })}
            </div>
            <div className="answer-review-scroll">
              <div className="answer-review">
                {subjectNames.map((subjectName, column) => {
                  const subjectIdKey = `subject${column + 1}Id`;
                  const barcodeStudentKey = `subject${column + 1}StudentId`;
                  const subjectIdIndex = fieldIndex(subjectIdKey);
                  const barcodeStudentIndex = fieldIndex(barcodeStudentKey);
                  const subjectId = reviewField(subjectIdKey);
                  const barcodeStudent = reviewField(barcodeStudentKey);
                  return <section className="review-subject" key={column}>
                    <header className="review-subject-heading">
                      <h3>{subjectName}</h3>
                      <label>Subject ID
                        <input value={subjectId?.value ?? ""} onChange={(event) => subjectIdIndex >= 0 && editField(subjectIdIndex, event.target.value)} />
                      </label>
                      <label>Barcode Student ID
                        <input value={barcodeStudent?.value ?? ""} onChange={(event) => barcodeStudentIndex >= 0 && editField(barcodeStudentIndex, event.target.value)} />
                      </label>
                      {(!subjectId?.value || !barcodeStudent?.value) && <small>Barcode value missing · enter manually</small>}
                    </header>
                    <div className="review-question-list">
                      {Array.from({ length: 50 }, (_, question) => {
                        const key = `subject${column + 1}Question${question + 1}`;
                        const index = fieldIndex(key);
                        const field = reviewField(key);
                        const flagged = field?.status === "multiple" || field?.status === "uncertain";
                        return <div className={`review-question ${flagged ? "needs-review" : ""}`} key={key}>
                          <span className="review-question-number">{String(question + 1).padStart(2, "0")}</span>
                          <div className="review-radio-group" role="radiogroup" aria-label={`${subjectName}, question ${question + 1}`}>
                            {answerChoices.map((choice) => <label className="review-radio-option" key={choice}>
                              <input type="radio" name={key} value={choice} checked={field?.value === choice} disabled={index < 0}
                                onChange={() => index >= 0 && editField(index, choice)} />
                              <span>{choice}</span>
                            </label>)}
                          </div>
                        </div>;
                      })}
                    </div>
                  </section>;
                })}
              </div>
            </div>
          </div>}
        </section>
      </div>
    </main>
    {camera && <CameraCapture onClose={() => setCamera(false)} onCapture={(file) => { setCamera(false); void loadSheet(file); }} />}
  </>;
}
