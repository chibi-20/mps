import { useEffect, useRef, useState } from "react";
import {
  ArrowRight,
  Check,
  ChevronLeft,
  ChevronRight,
  Code2,
  Copy,
  Download,
  FileText,
  Layers,
  Plus,
  ScanLine,
  Sparkles,
  Trash2,
  Upload,
  Camera,
  WifiOff,
} from "lucide-react";
import type { DocumentMeta, Field, TextLine } from "./types";
import {
  closeDocument,
  localOcr,
  openDocument,
  renderPage,
  type ScanSource,
} from "./services/ocr";
import { maxFileMb, validateFile, downloadJson } from "./services/files";
import { parseLines, validateFields } from "./services/parser";
import { buildPayload } from "./services/payload";
import { documentApi, mockMode } from "./services/api";
import CameraCapture from "./components/CameraCapture";
import InstallButton from "./components/InstallButton";
import AnswerSheetScanner from "./components/AnswerSheetScanner";
export default function App() {
  const [source, setSource] = useState<ScanSource | null>(null);
  const [mode, setMode] = useState<"document" | "answerSheet">("document");
  const sourceRef = useRef<ScanSource | null>(null);
  const [meta, setMeta] = useState<DocumentMeta | null>(null);
  const [page, setPage] = useState(1);
  const [fields, setFields] = useState<Field[]>([]);
  const [unmatched, setUnmatched] = useState<TextLine[]>([]);
  const [raw, setRaw] = useState("");
  const [scanned, setScanned] = useState(false);
  const [busy, setBusy] = useState(false);
  const lock = useRef(false);
  const [progress, setProgress] = useState(0);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [camera, setCamera] = useState(false);
  const [tab, setTab] = useState<"fields" | "json">("fields");
  const [drag, setDrag] = useState(false);
  const [apiState, setApiState] = useState<
    "idle" | "loading" | "success" | "error"
  >("idle");
  const [online, setOnline] = useState(navigator.onLine);
  const input = useRef<HTMLInputElement>(null);
  const preview = useRef<HTMLDivElement>(null);
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
  useEffect(() => {
    if (!source?.pdf || !preview.current) return;
    let active = true;
    const canvas = document.createElement("canvas");
    preview.current.replaceChildren(canvas);
    void renderPage(source, page, canvas).catch(() => {
      if (active)
        setError("Could not display this PDF page. Try another document.");
    });
    return () => {
      active = false;
    };
  }, [source, page, mode]);
  const errors = validateFields(fields);
  const payload =
    meta && !errors.length ? buildPayload(meta, fields, unmatched, raw) : null;
  const json = payload
    ? JSON.stringify(payload, null, 2)
    : JSON.stringify({ validationErrors: errors }, null, 2);
  async function select(file?: File) {
    if (!file || lock.current) return;
    lock.current = true;
    setBusy(true);
    setError("");
    setNotice("");
    setStatus("Opening document…");
    try {
      validateFile(file);
      const next = await openDocument(file);
      if (sourceRef.current) closeDocument(sourceRef.current);
      sourceRef.current = next;
      setSource(next);
      setMeta({
        fileName: file.name,
        fileType: next.pdf
          ? "application/pdf"
          : file.type || `image/${file.name.split(".").pop()}`,
        pageCount: next.pageCount,
        capturedAt: new Date().toISOString(),
      });
      setPage(1);
      setScanned(false);
      setFields([]);
      setUnmatched([]);
      setRaw("");
      setApiState("idle");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }
  function remove() {
    if (lock.current) return;
    if (sourceRef.current) closeDocument(sourceRef.current);
    sourceRef.current = null;
    setSource(null);
    setMeta(null);
    setScanned(false);
    setFields([]);
    setRaw("");
    setUnmatched([]);
    setError("");
    setNotice("");
    setApiState("idle");
  }
  async function scan() {
    if (!source || lock.current) return;
    lock.current = true;
    setBusy(true);
    setError("");
    setProgress(0);
    try {
      const lines = await localOcr.extract(source, (p, s) => {
        setProgress(p);
        setStatus(s);
      });
      const result = parseLines(lines);
      setFields(result.fields);
      setUnmatched(result.unmatchedText);
      setRaw(lines.map((l) => l.text).join("\n"));
      setScanned(true);
      setTab("fields");
      setApiState("idle");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      lock.current = false;
      setBusy(false);
    }
  }
  function edit(index: number, change: Partial<Field>) {
    setFields((old) =>
      old.map((f, i) =>
        i === index
          ? {
              ...f,
              ...change,
              ...(f.status && change.value !== undefined
                ? { status: "reviewed" as const, confidence: 1 }
                : {}),
            }
          : f,
      ),
    );
    setApiState("idle");
    setNotice("");
  }
  async function submit() {
    if (!payload || lock.current) return;
    lock.current = true;
    setApiState("loading");
    try {
      await documentApi.submitScannedDocument(payload);
      setApiState("success");
    } catch {
      setApiState("error");
    } finally {
      lock.current = false;
    }
  }
  return (
    <>
      <header className="topbar">
        <a className="brand" href="/" aria-label="MPS Scanner home">
          <span className="brand-icon">
            <ScanLine size={23} />
          </span>
          MPS Scanner
        </a>
        <div className="header-right">
          <InstallButton />
        </div>
      </header>
      <main>
        <label className="scan-mode">
          Document type
          <select
            value={mode}
            disabled={busy || apiState === "loading"}
            onChange={(e) => {
              setMode(e.target.value as "document" | "answerSheet");
              setScanned(false);
              setFields([]);
              setUnmatched([]);
              setRaw("");
              setApiState("idle");
            }}
          >
            <option value="document">Document / text</option>
            <option value="answerSheet">50-question answer sheet (A–D)</option>
          </select>
        </label>
        <div className="intro">
          <div>
            <h1>Scan a document</h1>
          </div>
        </div>
        <nav className="steps" aria-label="Scanning workflow">
          {["Add document", "Scan & extract", "Review & export"].map(
            (label, i) => (
              <div
                key={label}
                className={
                  (scanned ? 2 : source ? 1 : 0) === i
                    ? "active"
                    : (scanned ? 2 : source ? 1 : 0) > i
                      ? "complete"
                      : ""
                }
              >
                <span>
                  {(scanned ? 2 : source ? 1 : 0) > i ? (
                    <Check size={14} />
                  ) : (
                    i + 1
                  )}
                </span>
                {label}
                {i < 2 && <ChevronRight size={15} />}
              </div>
            ),
          )}
        </nav>
        {busy && !source && (
          <div className="banner" role="status">
            {status}
          </div>
        )}
        {!online && (
          <div className="banner">
            <WifiOff size={17} /> You’re offline. Scanning works if the app’s
            OCR resources have finished caching.
          </div>
        )}
        {error && (
          <div className="error" role="alert">
            {error}
          </div>
        )}
        {notice && (
          <div className="banner" role="status">
            {notice}
          </div>
        )}
        <div className="workspace">
          <section className="panel document-panel">
            <div className="panel-heading">
              <div>
                <h2>{source ? "Preview document" : "Add a document"}</h2>
              </div>
            </div>
            {!source ? (
              <>
                <div
                  className={`dropzone ${drag ? "dragging" : ""}`}
                  onDragOver={(e) => {
                    e.preventDefault();
                    setDrag(true);
                  }}
                  onDragLeave={() => setDrag(false)}
                  onDrop={(e) => {
                    e.preventDefault();
                    setDrag(false);
                    if (e.dataTransfer.files.length > 1)
                      setError("Please choose one document at a time.");
                    else void select(e.dataTransfer.files[0]);
                  }}
                >
                  <div className="upload-art">
                    <FileText size={36} />
                    <span>
                      <Plus size={16} />
                    </span>
                  </div>
                  <p>Drop a file here or choose one below.</p>
                  <button
                    className="primary"
                    disabled={busy}
                    onClick={() => input.current?.click()}
                  >
                    <Upload size={17} /> Choose a document
                  </button>
                  <span className="file-hint">
                    PDF, JPG, PNG, WEBP · Up to {maxFileMb} MB
                  </span>
                  <div className="or">
                    <span />
                    or
                    <span />
                  </div>
                  <button
                    className="camera-button"
                    disabled={busy}
                    onClick={() => setCamera(true)}
                  >
                    <Camera size={18} /> Use your camera
                  </button>
                </div>
                <div className="tip">
                  <Sparkles size={18} />
                  <div>
                    <p>
                      Use good lighting and keep the entire document in view.
                    </p>
                  </div>
                </div>
              </>
            ) : (
              <>
                <div className="file-info">
                  <FileText size={24} />
                  <div>
                    <strong>{source.file.name}</strong>
                    <small>
                      {meta?.fileType} ·{" "}
                      {(source.file.size / 1024 / 1024).toFixed(2)} MB ·{" "}
                      {source.pageCount}{" "}
                      {source.pageCount === 1 ? "page" : "pages"}
                    </small>
                  </div>
                  <button
                    aria-label="Remove document"
                    disabled={busy || apiState === "loading"}
                    onClick={remove}
                  >
                    <Trash2 size={17} />
                  </button>
                </div>
                {mode === "answerSheet" ? (
                  <AnswerSheetScanner
                    key={source.url}
                    source={source}
                    onResult={(result) => {
                      setFields(result);
                      setUnmatched([]);
                      setRaw("");
                      setScanned(true);
                      setTab("fields");
                      setApiState("idle");
                    }}
                  />
                ) : (
                  <>
                    <div
                      className="preview"
                      ref={source.pdf ? preview : undefined}
                    >
                      {!source.pdf && (
                        <img src={source.url} alt="Selected document preview" />
                      )}
                    </div>
                    <div className="page-controls">
                      <button
                        aria-label="Previous page"
                        disabled={page === 1 || busy}
                        onClick={() => setPage((p) => p - 1)}
                      >
                        <ChevronLeft size={17} />
                      </button>
                      <span>
                        Page {page} of {source.pageCount}
                      </span>
                      <button
                        aria-label="Next page"
                        disabled={page === source.pageCount || busy}
                        onClick={() => setPage((p) => p + 1)}
                      >
                        <ChevronRight size={17} />
                      </button>
                    </div>
                    <div className="scan-action">
                      <button
                        className="primary"
                        disabled={busy || apiState === "loading"}
                        onClick={scan}
                      >
                        <ScanLine size={18} />
                        {busy
                          ? "Processing…"
                          : scanned
                            ? "Scan again"
                            : "Scan & extract"}
                        <ArrowRight size={17} />
                      </button>
                      {busy && (
                        <div role="status">
                          <progress value={progress} max={100} />
                          <small>{status}</small>
                        </div>
                      )}
                    </div>
                  </>
                )}
              </>
            )}
            <input
              ref={input}
              type="file"
              accept=".pdf,.jpg,.jpeg,.png,.webp"
              hidden
              onChange={(e) => {
                void select(e.target.files?.[0]);
                e.target.value = "";
              }}
            />
          </section>
          <section className="panel results-panel">
            <div className="panel-heading">
              <div>
                <h2>Review & export</h2>
              </div>
            </div>
            <div className="tabs" role="tablist" aria-label="Results view">
              <button
                role="tab"
                aria-selected={tab === "fields"}
                className={tab === "fields" ? "selected" : ""}
                onClick={() => setTab("fields")}
              >
                <Layers size={16} /> Extracted fields
              </button>
              <button
                role="tab"
                aria-selected={tab === "json"}
                className={tab === "json" ? "selected" : ""}
                onClick={() => setTab("json")}
              >
                <Code2 size={17} /> JSON preview
              </button>
            </div>
            {!scanned ? (
              <div className="results-empty">
                <div className="result-illustration">
                  <div>
                    <span />
                    <i />
                  </div>
                  <div>
                    <span />
                    <i />
                  </div>
                  <div>
                    <span />
                    <i />
                  </div>
                  <span className="check-bubble">
                    <Check size={20} />
                  </span>
                </div>
                <p>
                  {source
                    ? mode === "answerSheet"
                      ? "Check the alignment, then select Read answer sheet."
                      : "Select Scan & extract to review the fields."
                    : "Add a document to get started."}
                </p>
              </div>
            ) : (
              <div className="result-content">
                {errors.length > 0 && (
                  <div className="error" role="alert">
                    {errors.map((e, i) => (
                      <p key={i}>{e}</p>
                    ))}
                  </div>
                )}
                {tab === "fields" ? (
                  <>
                    <div className="review-note">
                      Check the values before exporting, especially amber
                      fields.
                    </div>
                    <div className="field-list">
                      {fields.map((f, i) => (
                        <div
                          className={`field-card ${f.confidence < 0.8 ? "uncertain" : ""}`}
                          key={i}
                        >
                          <div className="field-meta">
                            <span>
                              {f.status && (
                                <strong>
                                  {f.status === "multiple"
                                    ? "Multiple marks · check"
                                    : f.status === "uncertain"
                                      ? "Faint mark · check"
                                      : f.status === "blank"
                                        ? "Blank · check"
                                        : f.status === "unreviewed"
                                          ? "Enter value"
                                          : f.status}{" "}
                                  ·{" "}
                                </strong>
                              )}
                              FIELD {String(i + 1).padStart(2, "0")} · PAGE{" "}
                              {f.page}
                            </span>
                            <span>
                              {Math.round(f.confidence * 100)}% confidence{" "}
                              <button
                                disabled={apiState === "loading"}
                                aria-label={`Delete field ${i + 1}`}
                                onClick={() => {
                                  setFields((old) =>
                                    old.filter((_, n) => n !== i),
                                  );
                                  setApiState("idle");
                                }}
                              >
                                <Trash2 size={14} />
                              </button>
                            </span>
                          </div>
                          <div className="field-pair">
                            <label>
                              Label
                              <input
                                disabled={apiState === "loading"}
                                value={f.label}
                                onChange={(e) =>
                                  edit(i, { label: e.target.value })
                                }
                              />
                            </label>
                            <label>
                              JSON key
                              <input
                                disabled={apiState === "loading"}
                                value={f.key}
                                onChange={(e) =>
                                  edit(i, { key: e.target.value })
                                }
                                aria-invalid={
                                  !f.key.trim() ||
                                  fields.filter((x) => x.key === f.key).length >
                                    1
                                }
                              />
                            </label>
                          </div>
                          <label>
                            Value
                            <textarea
                              disabled={apiState === "loading"}
                              rows={1}
                              value={f.value}
                              onChange={(e) =>
                                edit(i, { value: e.target.value })
                              }
                            />
                          </label>
                        </div>
                      ))}
                    </div>
                    <button
                      className="add-field"
                      disabled={apiState === "loading"}
                      onClick={() => {
                        setFields((old) => [
                          ...old,
                          {
                            key: "",
                            label: "",
                            value: "",
                            confidence: 1,
                            page,
                          },
                        ]);
                        setApiState("idle");
                      }}
                    >
                      <Plus size={16} /> Add a field
                    </button>
                    <details>
                      <summary>Unmatched text ({unmatched.length})</summary>
                      {unmatched.map((l, i) => (
                        <p key={i}>
                          <small>Page {l.page}</small> {l.text}
                        </p>
                      ))}
                    </details>
                    <details>
                      <summary>Full extracted text</summary>
                      <pre>{raw}</pre>
                    </details>
                  </>
                ) : (
                  <>
                    <p className="review-note">
                      {errors.length
                        ? "Resolve key errors to generate the final payload."
                        : "Copy or export the JSON below."}
                    </p>
                    <pre className="json-view">{json}</pre>
                  </>
                )}
              </div>
            )}
            <div className="export-bar">
              <div>
                <button
                  aria-label="Copy JSON"
                  disabled={!scanned || !!errors.length}
                  onClick={async () => {
                    try {
                      await navigator.clipboard.writeText(json);
                      setNotice("JSON copied to clipboard.");
                    } catch {
                      setError(
                        "Clipboard access was denied. Download the JSON instead.",
                      );
                    }
                  }}
                >
                  <Copy size={16} /> Copy
                </button>
                <button
                  disabled={!scanned || !!errors.length}
                  onClick={() => downloadJson(json, source!.file.name)}
                >
                  <Download size={16} /> Export JSON
                </button>
              </div>
            </div>
            {scanned && (
              <div className="api-bar">
                <button
                  disabled={
                    !mockMode || !!errors.length || apiState === "loading"
                  }
                  onClick={submit}
                >
                  {apiState === "loading"
                    ? "Simulating…"
                    : apiState === "error"
                      ? "Retry mock submission"
                      : "Submit to API · mock"}
                </button>
                {apiState !== "idle" && (
                  <small role="status">
                    {apiState === "success"
                      ? "Mock submission accepted. No data left your device."
                      : apiState === "error"
                        ? "Mock submission failed. Please retry."
                        : ""}
                  </small>
                )}
              </div>
            )}
          </section>
        </div>
      </main>
      {camera && (
        <CameraCapture
          onClose={() => setCamera(false)}
          onCapture={(file) => {
            setCamera(false);
            void select(file);
          }}
        />
      )}
    </>
  );
}
