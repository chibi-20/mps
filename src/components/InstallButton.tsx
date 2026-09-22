import { useEffect, useState } from "react";
import { Download } from "lucide-react";
interface InstallEvent extends Event {
  prompt: () => Promise<void>;
  userChoice: Promise<{ outcome: string }>;
}
export default function InstallButton() {
  const [prompt, setPrompt] = useState<InstallEvent | null>(null);
  const [help, setHelp] = useState(false);
  const [installed, setInstalled] = useState(
    window.matchMedia("(display-mode: standalone)").matches,
  );
  useEffect(() => {
    const handler = (event: Event) => {
      event.preventDefault();
      setPrompt(event as InstallEvent);
    };
    const done = () => {
      setInstalled(true);
      setPrompt(null);
    };
    window.addEventListener("beforeinstallprompt", handler);
    window.addEventListener("appinstalled", done);
    return () => {
      window.removeEventListener("beforeinstallprompt", handler);
      window.removeEventListener("appinstalled", done);
    };
  }, []);
  const ios =
    /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  return installed ? (
    <span className="subtle">App installed</span>
  ) : (
    <div className="install">
      <button
        onClick={async () => {
          if (prompt) {
            await prompt.prompt();
            await prompt.userChoice;
            setPrompt(null);
          } else setHelp(!help);
        }}
      >
        <Download size={16} /> Install app
      </button>
      {help && (
        <div className="install-help" role="status">
          {ios
            ? "In Safari, tap Share, then Add to Home Screen, then Add."
            : "Open this app over HTTPS in Chrome or Edge. When ready, use the install icon in the address bar or the browser menu → Install app."}
        </div>
      )}
    </div>
  );
}
