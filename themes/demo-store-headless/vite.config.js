/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    // Target modern browsers for smaller output
    target: 'es2020',
    // Enable minification
    minify: 'esbuild',
    // Increase chunk warning limit
    chunkSizeWarningLimit: 600,
    rollupOptions: {
      output: {
        // Single bundle — WordPress serves from a nested path that
        // breaks Vite's dynamic-import chunk resolution, so we inline
        // everything into one entry file (matches original working build).
        entryFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash].[ext]',
        manualChunks: undefined,
        inlineDynamicImports: true
      }
    },
    // All CSS in one file (no per-route splitting)
    cssCodeSplit: false
  },
  esbuild: {
    drop: ['debugger']
  }
})
