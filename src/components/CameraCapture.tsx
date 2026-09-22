import { useEffect, useRef, useState } from "react";
import { Camera, X } from "lucide-react";
export default function CameraCapture({
  onCapture,
  onClose,
}: {
  onCapture: (file: File) => void;
  onClose: () => void;
}) {
  const video = useRef<HTMLVideoElement>(null);
  const stream = useRef<MediaStream | null>(null);
  const panel = useRef<HTMLElement>(null);
  const [error, setError] = useState("");
  const [ready, setReady] = useState(false);
  useEffect(() => {
    let active = true;
    const previous = document.activeElement as HTMLElement | null;
    async function start() {
      try {
        const media = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: { ideal: "environment" } },
          audio: false,
        });
        if (!active) {
          media.getTracks().forEach((t) => t.stop());
          return;
        }
        stream.current = media;
        if (video.current) {
          video.current.srcObject = media;
          await video.current.play();
          if (active) setReady(true);
        }
      } catch {
        if (active)
          setError(
            "Camera access is unavailable or was denied. Allow camera permission in your browser settings, or upload a photo instead. Camera access requires HTTPS or localhost.",
          );
      }
    }
    void start();
    return () => {
      active = false;
      stream.current?.getTracks().forEach((t) => t.stop());
      previous?.focus();
    };
  }, []);
  function capture() {
    const v = video.current;
    if (!v?.videoWidth) return;
    const canvas = document.createElement("canvas");
    canvas.width = v.videoWidth;
    canvas.height = v.videoHeight;
    canvas.getContext("2d")!.drawImage(v, 0, 0);
    canvas.toBlob(
      (blob) => {
        if (blob)
          onCapture(
            new File([blob], `capture-${Date.now()}.jpg`, {
              type: "image/jpeg",
            }),
          );
        else setError("Could not capture the photo. Please retry.");
      },
      "image/jpeg",
      0.92,
    );
  }
  return (
    <div
      className="modal"
      role="dialog"
      aria-modal="true"
      aria-label="Capture a document"
      onKeyDown={(e) => {
        if (e.key === "Escape") onClose();
        if (e.key === "Tab") {
          const buttons = panel.current?.querySelectorAll<HTMLButtonElement>(
            "button:not(:disabled)",
          );
          if (!buttons?.length) return;
          const first = buttons[0],
            last = buttons[buttons.length - 1];
          if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
          } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
          }
        }
      }}
    >
      <section ref={panel} className="camera-panel">
        <div className="section-title">
          <h2>Capture a document</h2>
          <button autoFocus onClick={onClose} aria-label="Close camera">
            <X size={20} />
          </button>
        </div>
        <p>Place the whole document in the frame with good lighting.</p>
        {error ? (
          <p role="alert" className="error">
            {error}
          </p>
        ) : (
          <video ref={video} playsInline muted />
        )}
        <button className="primary" disabled={!ready} onClick={capture}>
          <Camera size={18} /> Take photo
        </button>
        <button onClick={onClose}>Cancel</button>
      </section>
    </div>
  );
}
