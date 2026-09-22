import { chromium } from "@playwright/test";
import { PDFDocument, StandardFonts } from "pdf-lib";
import assert from "node:assert/strict";
const browser = await chromium.launch({ channel: "msedge", headless: true });
const context = await browser.newContext();
const page = await context.newPage();
const errors = [];
page.on("pageerror", (e) => errors.push(e.message));
await page.goto("http://localhost:4173");
const pdf = await PDFDocument.create();
const font = await pdf.embedFont(StandardFonts.Helvetica);
pdf
  .addPage([600, 800])
  .drawText("Invoice Number: INV-002", { x: 50, y: 700, size: 20, font });
pdf
  .addPage([600, 800])
  .drawText("Customer Name: Ana Cruz", { x: 50, y: 700, size: 20, font });
await page
  .locator("input[type=file]")
  .setInputFiles({
    name: "two-pages.pdf",
    mimeType: "application/pdf",
    buffer: Buffer.from(await pdf.save()),
  });
await page.getByText("Page 1 of 2", { exact: true }).waitFor();
await page.getByRole("button", { name: "Next page" }).click();
await page.getByText("Page 2 of 2", { exact: true }).waitFor();
await page.getByRole("button", { name: "Scan & extract", exact: true }).click();
await page.getByRole("button", { name: "Scan again" }).waitFor();
await page.getByRole("tab", { name: "JSON preview" }).click();
let payload = JSON.parse(await page.locator(".json-view").innerText());
assert.equal(payload.fields.invoiceNumber, "INV-002");
assert.equal(payload.fieldDetails[1].page, 2);
assert.equal(payload.document.pageCount, 2);
await page.getByRole("button", { name: "Remove document" }).click();
const image = await page.evaluate(() => {
  const c = document.createElement("canvas");
  c.width = 1200;
  c.height = 400;
  const x = c.getContext("2d");
  x.fillStyle = "white";
  x.fillRect(0, 0, 1200, 400);
  x.fillStyle = "black";
  x.font = "42px Arial";
  x.fillText("Name: Maria Santos", 60, 100);
  return c.toDataURL("image/png").split(",")[1];
});
const scan = await PDFDocument.create();
const img = await scan.embedPng(Buffer.from(image, "base64"));
scan
  .addPage([600, 200])
  .drawImage(img, { x: 0, y: 0, width: 600, height: 200 });
await page
  .locator("input[type=file]")
  .setInputFiles({
    name: "scanned.pdf",
    mimeType: "application/pdf",
    buffer: Buffer.from(await scan.save()),
  });
await page.getByRole("button", { name: "Scan & extract", exact: true }).click();
await page
  .getByRole("button", { name: "Scan again" })
  .waitFor({ timeout: 120000 });
await page.getByRole("tab", { name: "JSON preview" }).click();
payload = JSON.parse(await page.locator(".json-view").innerText());
assert.equal(payload.fields.name, "Maria Santos");
await page.getByRole("button", { name: "Remove document" }).click();
await page.getByRole("button", { name: "Use your camera" }).click();
await page.getByRole("alert").filter({ hasText: "Camera access" }).waitFor();
await page.getByRole("button", { name: "Close camera" }).click();
assert.equal(errors.length, 0, errors.join("\n"));
console.log(
  "PASS: PDF text extraction, two-page navigation and metadata, scanned PDF OCR, camera denied handling.",
);
await browser.close();
