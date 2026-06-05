/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 *
 * NOTE: the demo-data fallback at the bottom is demo-store specific.
 * base-headless ships without it. Skip when porting back.
 *
 * NOTE: getPriceRange() and minPrice/maxPrice filtering are demo-store
 * specific — do not port to base-headless.
 */

import { storeApiRequest } from './storeApi';
import { demoProducts, getDemoProductBySlug } from '../data/demoProducts';

const isDemo = typeof window !== 'undefined' && !window.wpData;

export async function getProducts({
  perPage = 12,
  page = 1,
  search,
  category,
  minPrice,
  maxPrice,
  currencyMinorUnit = 2,
} = {}) {
  if (isDemo) {
    let filtered = demoProducts;

    if (minPrice !== undefined && minPrice !== null) {
      const minMinor = Math.round(minPrice * Math.pow(10, currencyMinorUnit));
      filtered = filtered.filter(
        (p) => Number(p.prices.price) >= minMinor
      );
    }
    if (maxPrice !== undefined && maxPrice !== null) {
      const maxMinor = Math.round(maxPrice * Math.pow(10, currencyMinorUnit));
      filtered = filtered.filter(
        (p) => Number(p.prices.price) <= maxMinor
      );
    }

    return filtered.slice(0, perPage);
  }

  const query = { per_page: perPage, page, search, category };

  if (minPrice !== undefined && minPrice !== null) {
    query.min_price = Math.round(minPrice * Math.pow(10, currencyMinorUnit));
  }
  if (maxPrice !== undefined && maxPrice !== null) {
    query.max_price = Math.round(maxPrice * Math.pow(10, currencyMinorUnit));
  }

  return storeApiRequest('products', { query });
}

export async function getProductBySlug(slug) {
  if (isDemo) {
    return getDemoProductBySlug(slug);
  }
  const results = await storeApiRequest('products', {
    query: { slug, per_page: 1 },
  });
  return Array.isArray(results) && results.length > 0 ? results[0] : null;
}

export async function getProductById(id) {
  if (isDemo) {
    return demoProducts.find((p) => p.id === id) || null;
  }
  return storeApiRequest(`products/${id}`);
}

/**
 * Fetch the min and max product prices in the catalog.
 * Returns { min, max, currencySymbol, currencyMinorUnit } in major units.
 *
 * NOTE: demo-store specific — do not port to base-headless.
 */
export async function getPriceRange() {
  if (isDemo) {
    const unit = demoProducts[0]?.prices?.currency_minor_unit ?? 2;
    const symbol = demoProducts[0]?.prices?.currency_symbol ?? '$';
    const divisor = Math.pow(10, unit);

    const prices = demoProducts.map((p) => Number(p.prices.price) / divisor);
    return {
      min: Math.floor(Math.min(...prices)),
      max: Math.ceil(Math.max(...prices)),
      currencySymbol: symbol,
      currencyMinorUnit: unit,
    };
  }

  // Live mode: paginate through all products to find the true min/max.
  // Each page fetches only the `prices` field to minimise payload.
  let page = 1;
  let allPriceData = [];
  let hasMore = true;

  while (hasMore) {
    const batch = await storeApiRequest('products', {
      query: { _fields: 'prices', per_page: 100, page },
    });
    if (!Array.isArray(batch) || batch.length === 0) break;
    allPriceData = allPriceData.concat(batch);
    hasMore = batch.length === 100;
    page += 1;
  }

  if (allPriceData.length === 0) {
    return { min: 0, max: 0, currencySymbol: '$', currencyMinorUnit: 2 };
  }

  const unit = allPriceData[0].prices.currency_minor_unit ?? 2;
  const symbol = allPriceData[0].prices.currency_symbol ?? '$';
  const divisor = Math.pow(10, unit);

  const prices = allPriceData
    .filter((p) => p.prices?.price != null)
    .map((p) => Number(p.prices.price) / divisor)
    .filter((n) => !Number.isNaN(n));

  if (prices.length === 0) {
    return { min: 0, max: 0, currencySymbol: symbol, currencyMinorUnit: unit };
  }

  return {
    min: Math.floor(Math.min(...prices)),
    max: Math.ceil(Math.max(...prices)),
    currencySymbol: symbol,
    currencyMinorUnit: unit,
  };
}
