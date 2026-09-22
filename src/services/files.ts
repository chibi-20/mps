export const maxFileMb =
  Number(import.meta.env.VITE_MAX_FILE_MB) > 0
    ? Number(import.meta.env.VITE_MAX_FILE_MB)
    : 25;
export function validateFile(file: File): void {
  if (!file.size)
    throw new Error("This file is empty. Choose a document with content.");
  if (file.size > maxFileMb * 1024 * 1024)
    throw new Error(`This file exceeds the ${maxFileMb} MB limit.`);
  if (!/\.(pdf|jpe?g|png|webp)$/i.test(file.name))
    throw new Error("Unsupported file type. Choose PDF, JPG, PNG, or WEBP.");
}
export function downloadJson(json: string, name: string) {
  const url = URL.createObjectURL(
    new Blob([json], { type: "application/json" }),
  );
  const link = document.createElement("a");
  link.href = url;
  link.download = name.replace(/\.[^.]+$/, "") + ".json";
  link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
