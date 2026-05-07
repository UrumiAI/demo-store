/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { storeApiRequest } from './storeApi';

export async function getProducts({ perPage = 12, page = 1, search, category } = {}) {
  return storeApiRequest('products', {
    query: {
      per_page: perPage,
      page,
      search,
      category,
    },
  });
}

export async function getProductBySlug(slug) {
  const results = await storeApiRequest('products', {
    query: { slug, per_page: 1 },
  });
  return Array.isArray(results) && results.length > 0 ? results[0] : null;
}

export async function getProductById(id) {
  return storeApiRequest(`products/${id}`);
}
