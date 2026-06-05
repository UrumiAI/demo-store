# Demo Store — Claude Code Instructions

## Platform: Urumi (myscalablesite.com)

This repo runs on the Urumi hosting platform. Each tenant has multiple **workspaces** (dev, staging, prod) accessible via SSH and MCP tools.

### Tenant slug

`demo-kkzi5m`

### Site URLs

| Workspace | URL | SSH user |
|-----------|-----|----------|
| dev-1 | `https://demo-kkzi5m-dev-1.myscalablesite.com` | `demo-kkzi5m-workspace-1` |
| dev-2 | `https://demo-kkzi5m-dev-2.myscalablesite.com` | `demo-kkzi5m-workspace-2` |
| prod | `https://demo-kkzi5m-prod.myscalablesite.com` | (use MCP tools) |

Use `wp option get siteurl` via SSH to confirm which URL a workspace serves.

## Deployment

### Key facts

- **No git repo on the workspace.** The WordPress install at `html/` is not a git checkout. You cannot `git pull` on the workspace.
- **No Node.js on the workspace.** You cannot run `npm run build` there.
- **Build locally, deploy via SCP.** Build the Vite bundle on your machine, then SCP the `dist/` files to the workspace.

### Deployment workflow (SCP)

1. **Build locally:**
   ```bash
   cd themes/demo-store-headless && npm run build
   ```

2. **Get the workspace absolute path** (do this once — it varies by workspace index):
   ```bash
   ssh demo-kkzi5m-workspace-1@ssh.myscalablesite.com "pwd"
   # Returns: /var/www/html/workspaces/1
   ```

3. **SCP the built files:**
   ```bash
   THEME_DIR="/var/www/html/workspaces/1/html/wp-content/themes/demo-store-headless"

   # Upload new dist files
   scp dist/index.html demo-kkzi5m-workspace-1@ssh.myscalablesite.com:${THEME_DIR}/dist/index.html
   scp dist/assets/index-NEWHASH.js demo-kkzi5m-workspace-1@ssh.myscalablesite.com:${THEME_DIR}/dist/assets/
   scp dist/assets/style-NEWHASH.css demo-kkzi5m-workspace-1@ssh.myscalablesite.com:${THEME_DIR}/dist/assets/
   ```

4. **Remove old hashed assets and flush cache:**
   ```bash
   ssh demo-kkzi5m-workspace-1@ssh.myscalablesite.com \
     "rm ${THEME_DIR}/dist/assets/index-OLDHASH.js ${THEME_DIR}/dist/assets/style-OLDHASH.css && wp cache flush"
   ```

   Vite produces content-hashed filenames. Always remove the old files after deploying new ones, otherwise stale assets accumulate.

### Important: workspace path structure

```
/var/www/html/workspaces/{index}/     # SSH home directory (pwd)
  html/                                # WordPress root
    wp-content/
      themes/demo-store-headless/
        dist/                          # Built Vite output (deploy here)
          index.html
          assets/
            index-{hash}.js
            style-{hash}.css
        src/                           # Source (not served directly)
```

## MCP Tools

### When to use MCP vs SSH vs local

| Task | Use |
|------|-----|
| Read/edit code | **Local git** (fastest) |
| Run wp-cli, test changes | **SSH** to workspace |
| Query analytics, metrics, APM | **MCP** (woocommerce, apm servers) — only way |
| Deploy code | **SCP** from local to workspace via SSH |
| Write files to workspace repo | **MCP** `write_files` (slow fallback, can't handle large files >100KB) |

### MCP workspace tools limitations

- `write_files` does atomic git commits on the workspace repo at `/var/www/html/workspaces/{index}/repo/` — this is a **separate path** from the live WordPress install at `html/`.
- `write_files` cannot handle large files (e.g., 225KB minified JS bundles). Use SCP for built assets.
- `git_create_branch` creates branches prefixed `urumi/ai/{index}/{slug}`.

## Headless Theme (demo-store-headless)

- **Stack:** React 18 + Vite + React Router 7
- **No external UI libraries** — plain CSS with design tokens in `src/styles/tokens.css`
- **Design system:** "Salve" cosmetics theme — sage accent (#5F6F52), warm neutrals, serif display font (EB Garamond), sans body (Inter)
- **SYNC comments:** Files marked with `SYNC:` header mirror `UrumiAI/base-headless`. Port changes both ways.
- **Demo mode:** When `window.wpData` is absent (local `npm run dev`), the app uses fixture data from `docs/sample-products.json`. Live mode hits the WooCommerce Store API.
- **Store API:** `wc/store/v1/` — supports `products`, `cart`, `checkout` endpoints. Currency and price units come from the API response (`prices.currency_code`, `prices.currency_minor_unit`).
