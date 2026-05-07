/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 *
 * NOTE: the demo-data fallback at the bottom is demo-store specific.
 * base-headless ships without it. Skip when porting back.
 */

import { storeApiRequest } from './storeApi';
import { demoProducts, getDemoProductBySlug } from '../data/demoProducts';

const isDemo = typeof window !== 'undefined' && !window.wpData;

export async function getProducts({ perPage = 12, page = 1, search, category } = {}) {
  if (isDemo) {
    return demoProducts.slice(0, perPage);
  }
  return storeApiRequest('products', {
    query: { per_page: perPage, page, search, category },
  });
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
