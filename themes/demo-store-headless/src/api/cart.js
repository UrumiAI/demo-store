/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { storeApiRequest } from './storeApi';

export function getCart() {
  return storeApiRequest('cart');
}

export function addCartItem({ id, quantity = 1, variation = [] }) {
  return storeApiRequest('cart/add-item', {
    method: 'POST',
    body: { id, quantity, variation },
  });
}

export function updateCartItem({ key, quantity }) {
  return storeApiRequest('cart/update-item', {
    method: 'POST',
    body: { key, quantity },
  });
}

export function removeCartItem({ key }) {
  return storeApiRequest('cart/remove-item', {
    method: 'POST',
    body: { key },
  });
}

export function clearCartItems() {
  return storeApiRequest('cart/items', { method: 'DELETE' });
}
