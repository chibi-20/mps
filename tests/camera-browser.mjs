import { chromium } from "@playwright/test";
import assert from "node:assert/strict";
const browser = await chromium.launch({
  channel: "msedge",
  headless: true,
  args: ["--use-fake-device-for-media-stream"],
});
const context = await browser.newContext({ permissions: ["camera"] });
const page = await context.newPage();
await page.goto("http://localhost:4173");
await page.getByRole("button", { name: "Use your camera" }).click();
await page.waitForFunction(
  () => document.querySelector("video")?.videoWidth > 0,
);
await page.evaluate(() => {
  window.captureTrack = document
    .querySelector("video")
    .srcObject.getVideoTracks()[0];
});
await page.getByRole("button", { name: "Take photo" }).click();
await page
  .getByRole("button", { name: "Scan & extract", exact: true })
  .waitFor();
assert.equal(
  await page.evaluate(() => window.captureTrack.readyState),
  "ended",
);
assert.ok(await page.locator(".preview img").isVisible());
console.log(
  "PASS: permitted camera capture creates preview and stops the media stream.",
);
await browser.close();
