# Demo Store — Claude Code Instructions

## Project overview

Headless React + WooCommerce cosmetic storefront ("Salve"). The theme lives in
`themes/demo-store-headless/` and uses Vite to build a React SPA that is served
by a PHP shell (`index.php` + `functions.php`).

## Build & deploy

The React app **must be built locally** before deploying to a workspace:

```bash
cd themes/demo-store-headless
npm install
npm run build        # outputs to dist/
```

The built `dist/` directory is committed to the repo and deployed via git push.
WordPress loads `dist/assets/index-*.js` and `dist/assets/style-*.css` from the
theme directory at runtime (see `functions.php` → `demo_store_get_assets()`).

**If you skip the build step, changes to source files under `src/` will not be
visible on the workspace.**

## Theme structure

- `src/` — React source (JSX, CSS modules)
  - `src/pages/` — route-level components (Home, Shop, ProductDetail, Cart, Checkout, OrderConfirmation)
  - `src/components/` — shared components (Header, Footer, ProductList, ProductCard, PriceSlider)
  - `src/api/` — WooCommerce Store API clients (storeApi, products, cart, checkout)
  - `src/styles/` — CSS files including design tokens (`tokens.css`)
  - `src/data/` — demo product fixtures for offline/demo mode
- `dist/` — Vite production build output (committed)
- `inc/` — PHP server-side rendering helpers (SSR router, SEO data, schema)
- `mu-plugins/` — WP-CLI seed scripts for demo data

## SYNC convention

Files marked with `SYNC:` comments at the top are shared with a sibling repo
(`UrumiAI/base-headless`). When modifying these files, port changes both ways
using the identifier mapping in the comment header.

## WooCommerce Store API

The React app uses the WooCommerce Store API (`/wp-json/wc/store/v1/`) for all
data fetching. Key endpoints: `products`, `cart`, `checkout`. The API supports
query params like `per_page`, `min_price`, `max_price`, `search`, `category`.

## Workspace deployment

**Preferred workflow: local git + SSH**

1. Edit code locally in the git clone.
2. Build locally (`npm run build` in the theme directory).
3. Test via SSH into the workspace — WordPress runs there.
   - SSH format: `ssh {tenant-slug}-workspace-{index}@ssh.myscalablesite.com`
   - Example: `ssh demo-kkzi5m-workspace-2@ssh.myscalablesite.com`
   - WordPress root is at `/var/www/html/` (the SSH home directory).
   - Run wp-cli: `wp option get siteurl`, `wp plugin list`, `wp cache flush`, etc.
4. Commit source + dist, push to GitHub to deploy.

**MCP tools (slow fallback)** — use only when SSH is unavailable:

- Workspace URL: check via `wp option get siteurl` (MCP wp_read)
- MCP branches use format: `urumi/ai/{workspace_index}/{slug}`
- Use `git_create_branch` MCP action to create deploy branches
- Use `write_files` MCP action to push source changes (dist must be built separately)
