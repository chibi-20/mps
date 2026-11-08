import { useState, type FormEvent } from "react";
import { loginToMps, type ScannerSession } from "../services/api";

export default function ScannerSignIn({ suggestedUrl, onSignedIn }: { suggestedUrl: string; onSignedIn: (session: ScannerSession) => void }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  async function submit(event: FormEvent) {
    event.preventDefault(); setError(""); setBusy(true);
    try {
      if (!suggestedUrl.trim()) throw new Error("Open MPS Scanner from the Install MPS Scanner link in your teacher dashboard.");
      onSignedIn(await loginToMps(suggestedUrl.trim(), username.trim(), password));
    }
    catch (e) { setError((e as Error).message); }
    finally { setBusy(false); }
  }
  return <main className="signin-wrap"><form className="panel signin-card" onSubmit={(e) => void submit(e)}>
    <div className="section-title"><strong>Teacher sign in</strong><span>Required before scanning</span></div>
    <p>Sign in with your MPS teacher account. Your scanner session is securely issued by MPS and expires after 8 hours.</p>
    <label>Username<input value={username} onChange={(e) => setUsername(e.target.value)} required autoComplete="username" /></label>
    <label>Password<input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" /></label>
    {error && <p className="error" role="alert">{error}</p>}
    <button className="primary" disabled={busy}>{busy ? "Signing in…" : "Sign in to MPS"}</button>
  </form></main>;
}
