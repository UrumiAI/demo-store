/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

/**
 * Low-level WooCommerce Store API client.
 *
 * The Store API issues a `Cart-Token` on the first cart response. We persist
 * it so the same cart follows the visitor across reloads. Each response also
 * rotates the `Nonce` header — we send the latest one back on POST/PUT/DELETE
 * to satisfy the API's CSRF protection.
 */

const CART_TOKEN_STORAGE_KEY = 'demo-store-cart-token';
let currentCartToken = null;

function getStoreApiRoot() {
  const configuredRoot = window.wpData?.storeApiRoot;
  if (!configuredRoot) {
    return `${window.location.origin}/wp-json/wc/store/v1/`;
  }

  // Preview environments can serve the storefront from a different hostname
  // than WordPress's configured canonical URL. Keep Store API requests on the
  // shopper's current origin so browser session cookies and cart tokens stay
  // attached to the same storefront session.
  const configuredUrl = new URL(configuredRoot, window.location.origin);
  return new URL(
    `${configuredUrl.pathname}${configuredUrl.search}`,
    window.location.origin
  ).toString();
}

function getStoredCartToken() {
  if (currentCartToken) return currentCartToken;
  try {
    return localStorage.getItem(CART_TOKEN_STORAGE_KEY) || null;
  } catch {
    return null;
  }
}

function storeCartToken(token) {
  if (!token) return;
  currentCartToken = token;
  try {
    localStorage.setItem(CART_TOKEN_STORAGE_KEY, token);
  } catch {
    // localStorage unavailable (private mode / quota); fall back to in-memory only.
  }
}

let currentNonce = window.wpData?.nonce || '';

export function clearStoredCartToken() {
  currentCartToken = null;
  try {
    localStorage.removeItem(CART_TOKEN_STORAGE_KEY);
  } catch {
    // ignore
  }
}

export async function storeApiRequest(endpoint, { method = 'GET', body, query } = {}) {
  const root = getStoreApiRoot();
  const url = new URL(endpoint.replace(/^\//, ''), root);

  if (query) {
    Object.entries(query).forEach(([key, value]) => {
      if (value !== undefined && value !== null) {
        url.searchParams.set(key, value);
      }
    });
  }

  const headers = {
    'Content-Type': 'application/json',
  };

  const cartToken = getStoredCartToken();
  if (cartToken) {
    headers['Cart-Token'] = cartToken;
  }

  if (method !== 'GET' && currentNonce) {
    headers['Nonce'] = currentNonce;
    headers['X-WP-Nonce'] = currentNonce;
  }

  const response = await fetch(url.toString(), {
    method,
    headers,
    credentials: 'include',
    body: body ? JSON.stringify(body) : undefined,
  });

  const newCartToken = response.headers.get('Cart-Token');
  if (newCartToken) {
    storeCartToken(newCartToken);
  }

  const newNonce = response.headers.get('Nonce');
  if (newNonce) {
    currentNonce = newNonce;
  }

  if (response.status === 204) {
    return null;
  }

  let payload = null;
  const contentType = response.headers.get('Content-Type') || '';
  if (contentType.includes('application/json')) {
    payload = await response.json();
  }

  if (!response.ok) {
    const message = payload?.message || `Store API request failed (${response.status})`;
    const error = new Error(message);
    error.status = response.status;
    error.code = payload?.code;
    error.data = payload?.data;
    throw error;
  }

  return payload;
}

export function formatMinorUnits(minor, currencyMinorUnit, currencySymbol = '') {
  if (minor === undefined || minor === null) return '';
  const divisor = Math.pow(10, currencyMinorUnit ?? 2);
  const value = (Number(minor) / divisor).toFixed(currencyMinorUnit ?? 2);
  return currencySymbol ? `${currencySymbol}${value}` : value;
}
