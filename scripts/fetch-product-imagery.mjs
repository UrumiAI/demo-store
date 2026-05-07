/**
 * Fetch curated cosmetic product imagery from Pexels and write a
 * sample-products fixture.
 *
 * Usage:  node scripts/fetch-product-imagery.mjs
 *
 * Reads PEXELS_API_KEY from ../.env (the wc-hosting-portal monorepo root).
 * Override with `PEXELS_API_KEY=... node scripts/...` if needed.
 *
 * Outputs:
 *   - themes/demo-store-headless/public/products/<slug>.jpg  (downloaded)
 *   - docs/sample-products.json  (fixture used by demo-data fallback + WP-CLI seed)
 */

import { readFileSync, writeFileSync, mkdirSync, createWriteStream } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Readable } from 'node:stream';
import { pipeline } from 'node:stream/promises';

const __dirname = dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = resolve(__dirname, '..');
const MONOREPO_ROOT = resolve(REPO_ROOT, '..');
const ENV_PATH = resolve(MONOREPO_ROOT, '.env');
const PUBLIC_PRODUCTS_DIR = resolve(REPO_ROOT, 'themes/demo-store-headless/public/products');
const FIXTURE_PATH = resolve(REPO_ROOT, 'docs/sample-products.json');

function loadEnvKey(name) {
  if (process.env[name]) return process.env[name];
  try {
    const raw = readFileSync(ENV_PATH, 'utf8');
    for (const line of raw.split(/\r?\n/)) {
      const m = line.match(new RegExp(`^${name}\\s*=\\s*(.+?)\\s*$`));
      if (m) {
        return m[1].replace(/^['"]|['"]$/g, '');
      }
    }
  } catch (err) {
    if (err.code !== 'ENOENT') throw err;
  }
  return null;
}

const PEXELS_API_KEY = loadEnvKey('PEXELS_API_KEY');
if (!PEXELS_API_KEY) {
  console.error('PEXELS_API_KEY not found in env or', ENV_PATH);
  process.exit(1);
}

/**
 * Curated SKU catalogue for the Salve apothecary brand.
 * Each entry pairs a product persona with the Pexels search term most likely
 * to surface a clean, on-brand still life.
 */
const CATALOGUE = [
  {
    name: 'Resurfacing Serum',
    slug: 'resurfacing-serum',
    sku: 'SLV-001',
    price: 2800,
    short_description: '10% glycolic acid. Nightly resurfacing for refined, even skin.',
    description: 'A gentle nightly serum formulated with 10% glycolic acid and panthenol. Resurfaces without stripping; pairs with the Hydrating Mist for a balanced routine. 30 mL.',
    query: 'skincare serum bottle minimalist',
  },
  {
    name: 'Geranium Hand Wash',
    slug: 'geranium-hand-wash',
    sku: 'SLV-002',
    price: 1400,
    short_description: 'A botanical hand wash with rose geranium and aloe.',
    description: 'Daily hand wash with rose geranium, aloe, and panthenol. Foams gently, rinses cleanly. Designed for the basin you see most. 500 mL.',
    query: 'hand wash bottle apothecary',
  },
  {
    name: 'Hydrating Face Mist',
    slug: 'hydrating-face-mist',
    sku: 'SLV-003',
    price: 1900,
    short_description: 'Rosewater and hyaluronic acid. Sets makeup, refreshes skin.',
    description: 'A weightless rosewater mist with low-molecular hyaluronic acid. Use post-cleanse, mid-day, or to set powder. 100 mL.',
    query: 'face mist spray bottle minimalist',
  },
  {
    name: 'Volume Two Cleanser',
    slug: 'volume-two-cleanser',
    sku: 'SLV-004',
    price: 2200,
    short_description: 'A gel-to-foam cleanser with green tea and amino acids.',
    description: 'Our second cleanser — gentler than the original, designed for daily use. Gel to foam, removes SPF and light makeup. 200 mL.',
    query: 'face cleanser bottle minimalist',
  },
  {
    name: 'Botanical Face Oil',
    slug: 'botanical-face-oil',
    sku: 'SLV-005',
    price: 3400,
    short_description: 'A nightly face oil with squalane, jojoba, and rosehip.',
    description: 'A non-comedogenic face oil pressed from squalane, jojoba, and rosehip. Two to three drops, evening only. Best applied to damp skin. 30 mL.',
    query: 'facial oil dropper bottle',
  },
  {
    name: 'Eucalyptus Body Wash',
    slug: 'eucalyptus-body-wash',
    sku: 'SLV-006',
    price: 1800,
    short_description: 'Body wash with eucalyptus, lemon myrtle, and oat extract.',
    description: 'A grounded morning shower with eucalyptus and lemon myrtle. Oat extract softens; lather is generous without being squeaky. 500 mL.',
    query: 'body wash bottle apothecary',
  },
  {
    name: 'Vitamin C Toner',
    slug: 'vitamin-c-toner',
    sku: 'SLV-007',
    price: 2100,
    short_description: 'Brightening toner with stable 5% ascorbic acid.',
    description: 'A water-light toner with 5% stabilised vitamin C and licorice root. Use morning under SPF; layer with a moisturiser. 150 mL.',
    query: 'toner bottle minimalist',
  },
  {
    name: 'Niacinamide Serum',
    slug: 'niacinamide-serum',
    sku: 'SLV-008',
    price: 2400,
    short_description: '10% niacinamide and zinc. For pores and blemish-prone skin.',
    description: 'A pore-refining serum with 10% niacinamide and 1% zinc PCA. Twice daily, on cleansed skin. Pairs well with the Resurfacing Serum on alternate nights. 30 mL.',
    query: 'serum dropper bottle minimalist',
  },
  {
    name: 'Amber Body Cream',
    slug: 'amber-body-cream',
    sku: 'SLV-009',
    price: 2600,
    short_description: 'A rich amber-scented body cream with shea and ceramides.',
    description: 'Our richest body cream — shea, ceramides, and a warm amber accord. Designed for after-shower or pre-bed. 200 mL jar, glass.',
    query: 'body cream jar minimalist',
  },
  {
    name: 'Restorative Lip Balm',
    slug: 'restorative-lip-balm',
    sku: 'SLV-010',
    price: 600,
    short_description: 'Beeswax and shea lip balm. Unscented.',
    description: 'A simple, restorative lip balm — beeswax, shea, jojoba, vitamin E. Unscented. 12 g tin.',
    query: 'lip balm tin natural',
  },
  {
    name: 'Calming Bath Salts',
    slug: 'calming-bath-salts',
    sku: 'SLV-011',
    price: 1600,
    short_description: 'Magnesium-rich bath salts with lavender and chamomile.',
    description: 'Coarse Himalayan and Epsom salts with dried lavender and chamomile. Two scoops to a warm bath. 400 g.',
    query: 'bath salts jar apothecary',
  },
  {
    name: 'Verbena Hand Cream',
    slug: 'verbena-hand-cream',
    sku: 'SLV-012',
    price: 1200,
    short_description: 'A non-greasy hand cream with shea butter and verbena.',
    description: 'A hand cream that absorbs in seconds. Shea butter, sweet almond oil, verbena. Carry it; reapply often. 75 mL tube.',
    query: 'hand cream tube minimalist',
  },
];

async function searchPexels(query) {
  const url = new URL('https://api.pexels.com/v1/search');
  url.searchParams.set('query', query);
  url.searchParams.set('per_page', '15');
  url.searchParams.set('orientation', 'portrait');
  url.searchParams.set('size', 'medium');

  const res = await fetch(url, {
    headers: { Authorization: PEXELS_API_KEY },
  });
  if (!res.ok) {
    throw new Error(`Pexels search failed (${res.status}) for "${query}"`);
  }
  const data = await res.json();
  return data.photos || [];
}

async function downloadImage(url, dest) {
  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(`Image download failed (${res.status}) ${url}`);
  }
  await pipeline(Readable.fromWeb(res.body), createWriteStream(dest));
}

function pickPhoto(photos, usedIds) {
  for (const p of photos) {
    if (!usedIds.has(p.id)) return p;
  }
  return photos[0] || null;
}

async function main() {
  mkdirSync(PUBLIC_PRODUCTS_DIR, { recursive: true });
  mkdirSync(dirname(FIXTURE_PATH), { recursive: true });

  const usedIds = new Set();
  const products = [];
  let id = 100;

  for (const item of CATALOGUE) {
    process.stdout.write(`• ${item.name.padEnd(28)} `);

    let photo;
    try {
      const photos = await searchPexels(item.query);
      photo = pickPhoto(photos, usedIds);
      if (!photo) {
        console.log('no results, skipping image');
      } else {
        usedIds.add(photo.id);
      }
    } catch (err) {
      console.log(`search error: ${err.message}`);
    }

    const fileName = `${item.slug}.jpg`;
    const filePath = resolve(PUBLIC_PRODUCTS_DIR, fileName);
    let imagePath = `/wp-content/themes/demo-store-headless/public/products/${fileName}`;
    let credit = null;

    if (photo) {
      try {
        await downloadImage(photo.src.large, filePath);
        credit = {
          photographer: photo.photographer,
          photographer_url: photo.photographer_url,
          source: 'Pexels',
          source_url: photo.url,
        };
        console.log(`✓ ${photo.photographer}`);
      } catch (err) {
        console.log(`download error: ${err.message}`);
        imagePath = '';
      }
    } else {
      imagePath = '';
    }

    products.push({
      id: id++,
      slug: item.slug,
      sku: item.sku,
      name: item.name,
      short_description: item.short_description,
      description: item.description,
      price: item.price,
      image: imagePath,
      credit,
    });
  }

  writeFileSync(FIXTURE_PATH, JSON.stringify({ products }, null, 2) + '\n');
  console.log(`\nWrote ${products.length} products to ${FIXTURE_PATH}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
