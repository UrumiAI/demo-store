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
import {
  getDemoCart,
  addDemoItem,
  updateDemoItem,
  removeDemoItem,
  clearDemoCart,
} from '../data/demoProducts';

const isDemo = typeof window !== 'undefined' && !window.wpData;

export function getCart() {
  if (isDemo) return Promise.resolve(getDemoCart());
  return storeApiRequest('cart');
}

export function addCartItem({ id, quantity = 1, variation = [] }) {
  if (isDemo) return Promise.resolve(addDemoItem({ id, quantity }));
  return storeApiRequest('cart/add-item', {
    method: 'POST',
    body: { id, quantity, variation },
  });
}

export function updateCartItem({ key, quantity }) {
  if (isDemo) return Promise.resolve(updateDemoItem({ key, quantity }));
  return storeApiRequest('cart/update-item', {
    method: 'POST',
    body: { key, quantity },
  });
}

export function removeCartItem({ key }) {
  if (isDemo) return Promise.resolve(removeDemoItem({ key }));
  return storeApiRequest('cart/remove-item', {
    method: 'POST',
    body: { key },
  });
}

export function clearCartItems() {
  if (isDemo) return Promise.resolve(clearDemoCart());
  return storeApiRequest('cart/items', { method: 'DELETE' });
}
