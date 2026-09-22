import { chromium } from "@playwright/test";
import assert from "node:assert/strict";
const browser = await chromium.launch({ channel: "msedge", headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const errors = [];
page.on("pageerror", (e) => errors.push(e.message));
await page.goto("http://localhost:4173");
await page.getByRole("heading", { name: "Scan a document" }).waitFor();
await page.screenshot({ path: "tests/desktop.png", fullPage: true });
await page.locator("input[type=file]").setInputFiles({
  name: "empty.pdf",
  mimeType: "application/pdf",
  buffer: Buffer.alloc(0),
});
await page.getByRole("alert").filter({ hasText: "empty" }).waitFor();
const image = await page.evaluate(() => {
  const canvas = document.createElement("canvas");
  canvas.width = 1300;
  canvas.height = 500;
  const c = canvas.getContext("2d");
  c.fillStyle = "white";
  c.fillRect(0, 0, 1300, 500);
  c.fillStyle = "black";
  c.font = "40px Arial";
  c.fillText("Name: Juan Dela Cruz", 70, 100);
  c.fillText("Invoice Number: INV-001", 70, 180);
  c.fillText("Total Amount: 1500.00", 70, 260);
  return canvas.toDataURL("image/png").split(",")[1];
});
await page.locator("input[type=file]").setInputFiles({
  name: "invoice.png",
  mimeType: "image/png",
  buffer: Buffer.from(image, "base64"),
});
await page.getByRole("button", { name: "Scan & extract", exact: true }).click();
await page
  .getByRole("button", { name: "Scan again" })
  .waitFor({ timeout: 120000 });
assert.ok(
  (await page.getByRole("textbox", { name: "Label", exact: true }).count()) >=
    3,
  "OCR extracts three fields",
);
await page.getByRole("tab", { name: "JSON preview" }).click();
const payload = JSON.parse(await page.locator(".json-view").innerText());
assert.equal(payload.fields.name, "Juan Dela Cruz");
assert.equal(payload.fields.invoiceNumber, "INV-001");
await page.getByRole("button", { name: "Submit to API · mock" }).click();
await page
  .getByText("Mock submission accepted. No data left your device.")
  .waitFor();
await page.screenshot({ path: "tests/results.png", fullPage: true });
await page.getByRole("tab", { name: "Extracted fields" }).click();
await page
  .getByRole("textbox", { name: "JSON key", exact: true })
  .nth(1)
  .fill("name");
await page.getByRole("alert").filter({ hasText: "Duplicate key" }).waitFor();
assert.ok(await page.getByRole("button", { name: "Export JSON" }).isDisabled());
await page.setViewportSize({ width: 390, height: 844 });
await page.screenshot({ path: "tests/mobile.png", fullPage: true });
assert.ok(
  await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
);
await page.waitForFunction(
  async () => !!(await navigator.serviceWorker.getRegistration())?.active,
  { timeout: 60000 },
);
await page.waitForFunction(() => !!navigator.serviceWorker.controller, {
  timeout: 60000,
});
await context.setOffline(true);
await page.reload();
await page.getByRole("heading", { name: "Scan a document" }).waitFor();
await page.locator("input[type=file]").setInputFiles({
  name: "offline.png",
  mimeType: "image/png",
  buffer: Buffer.from(image, "base64"),
});
await page.getByRole("button", { name: "Scan & extract", exact: true }).click();
await page
  .getByRole("button", { name: "Scan again" })
  .waitFor({ timeout: 120000 });
assert.equal(errors.length, 0, errors.join("\n"));
console.log(
  "PASS: upload validation, real image OCR, editing, duplicate detection, JSON, mock API, mobile layout, offline shell and OCR.",
);
await browser.close();
