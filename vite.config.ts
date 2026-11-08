import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { VitePWA } from "vite-plugin-pwa";
export default defineConfig({
  // Emit into /scanner and retain hashed chunks for tabs open during a deploy.
  // Relative URLs also allow deployments to mount this folder elsewhere.
  base: "./",
  build: { outDir: "scanner", emptyOutDir: false },
  plugins: [
    react(),
    VitePWA({
      registerType: "autoUpdate",
      includeAssets: ["icons/*.png"],
      manifest: {
        name: "MPS Scanner",
        short_name: "MPS Scanner",
        description:
          "Private, on-device document scanning and structured JSON.",
        theme_color: "#285c48",
        background_color: "#f5f7f4",
        display: "standalone",
        start_url: "./",
        scope: "./",
        icons: [
          { src: "./icons/icon-192.png", sizes: "192x192", type: "image/png" },
          {
            src: "./icons/icon-512.png",
            sizes: "512x512",
            type: "image/png",
            purpose: "any maskable",
          },
        ],
      },
      workbox: {
        maximumFileSizeToCacheInBytes: 16000000,
        globPatterns: ["**/*.{js,mjs,css,html,png,wasm,gz}"],
      },
    }),
  ],
});
