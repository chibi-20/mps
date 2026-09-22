import { chromium } from "@playwright/test";
import assert from "node:assert/strict";
const browser = await chromium.launch({ channel: "msedge", headless: true });
const context = await browser.newContext();
const page = await context.newPage();
await page.goto("http://localhost:4173");
await page.getByLabel("Document type").selectOption("answerSheet");
await page
  .locator("input[type=file]")
  .setInputFiles(
    process.env.ANSWER_SHEET_FIXTURE ||
      "C:/Users/dumal/AppData/Local/Temp/codex-clipboard-3c314201-e3e9-4cbd-afd1-683f6dfc36c1.png",
  );
await page
  .getByRole("button", { name: "Read answer sheet", exact: true })
  .waitFor();
await page.waitForFunction(
  () => !document.querySelector(".sheet-scanner>.primary")?.disabled,
);
console.log(
  "corners",
  await page
    .locator(".sheet-alignment svg g circle")
    .evaluateAll((nodes) =>
      nodes.map((n) => [n.getAttribute("cx"), n.getAttribute("cy")]),
    ),
);
await page.screenshot({ path: "tests/sheet-alignment.png", fullPage: true });
await page
  .getByRole("button", { name: "Read answer sheet", exact: true })
  .click();
await page.getByRole("tab", { name: "JSON preview" }).click();
let payload = JSON.parse(await page.locator(".json-view").innerText());
console.log(
  "blank answers",
  payload.fieldDetails
    .filter((f) => f.key.startsWith("question"))
    .reduce((acc, f) => ((acc[f.status] = (acc[f.status] || 0) + 1), acc), {}),
);
assert.equal(
  Object.keys(payload.fields).filter((k) => k.startsWith("question")).length,
  50,
);
assert.ok(
  payload.fieldDetails
    .filter((f) => f.key.startsWith("question"))
    .every((f) => f.status === "blank"),
);
const marked = await page.evaluate(() => {
  const c = document.querySelector(".sheet-alignment canvas");
  const ctx = c.getContext("2d");
  const circles = [...document.querySelectorAll(".sheet-alignment svg>circle")];
  const fill = (index) => {
    const p = circles[index];
    ctx.beginPath();
    ctx.arc(
      Number(p.getAttribute("cx")),
      Number(p.getAttribute("cy")),
      10,
      0,
      Math.PI * 2,
    );
    ctx.fillStyle = "#111";
    ctx.fill();
  };
  fill(0);
  fill(199);
  fill(4);
  fill(5);
  fill(202);
  fill(213);
  fill(224);
  fill(235);
  fill(246);
  return c.toDataURL("image/png").split(",")[1];
});
await page.getByRole("button", { name: "Remove document" }).click();
await page
  .locator("input[type=file]")
  .setInputFiles({
    name: "marked-sheet.png",
    mimeType: "image/png",
    buffer: Buffer.from(marked, "base64"),
  });
await page.waitForFunction(
  () => !document.querySelector(".sheet-scanner>.primary")?.disabled,
);
await page
  .getByRole("button", { name: "Read answer sheet", exact: true })
  .click();
await page.getByRole("tab", { name: "JSON preview" }).click();
payload = JSON.parse(await page.locator(".json-view").innerText());
assert.equal(payload.fields.question1, "A");
assert.equal(payload.fields.question50, "D");
assert.equal(
  payload.fieldDetails.find((f) => f.key === "question2").status,
  "multiple",
);
assert.equal(
  [1, 2, 3, 4, 5].map((i) => payload.fields["studentIdDigit" + i]).join(""),
  "23456",
);
console.log(
  "PASS: supplied blank sheet, 50 answers, synthetic A/D marks, multiple marks, 5 Student ID digits.",
);
await browser.close();
