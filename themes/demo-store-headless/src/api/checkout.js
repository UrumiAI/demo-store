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
import { getDemoCart, clearDemoCart } from '../data/demoProducts';

const isDemo = typeof window !== 'undefined' && !window.wpData;

export function getCheckout() {
  if (isDemo) {
    return Promise.resolve({
      payment_methods: ['demo'],
      billing_address: {},
      shipping_address: {},
    });
  }
  return storeApiRequest('checkout');
}

export function submitCheckout(payload) {
  if (isDemo) {
    const cart = getDemoCart();
    const fakeOrder = {
      order_id: Math.floor(Math.random() * 90000) + 10000,
      order_key: 'demo-' + Math.random().toString(36).slice(2, 10),
      status: 'processing',
      payment_method: payload.payment_method || 'demo',
      payment_method_title: 'Demo (no real charge)',
      billing_address: payload.billing_address,
      shipping_address: payload.shipping_address || payload.billing_address,
      items: cart.items,
      totals: cart.totals,
    };
    clearDemoCart();
    if (typeof window !== 'undefined') {
      sessionStorage.setItem(`demo-order-${fakeOrder.order_id}`, JSON.stringify(fakeOrder));
    }
    return Promise.resolve(fakeOrder);
  }
  return storeApiRequest('checkout', { method: 'POST', body: payload });
}

export function getOrder(orderId, orderKey) {
  if (isDemo) {
    if (typeof window !== 'undefined') {
      const stored = sessionStorage.getItem(`demo-order-${orderId}`);
      if (stored) return Promise.resolve(JSON.parse(stored));
    }
    return Promise.reject(new Error('Order not found'));
  }
  return storeApiRequest(`order/${orderId}`, { query: { key: orderKey } });
}

export function validateCheckoutForm(data) {
  const errors = {};
  const billing = data.billing_address || {};

  if (!billing.first_name?.trim()) errors.billing_first_name = 'First name is required';
  if (!billing.last_name?.trim()) errors.billing_last_name = 'Last name is required';
  if (!billing.email?.trim()) {
    errors.billing_email = 'Email is required';
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(billing.email)) {
    errors.billing_email = 'Invalid email format';
  }
  if (!billing.phone?.trim()) errors.billing_phone = 'Phone number is required';
  if (!billing.address_1?.trim()) errors.billing_address_1 = 'Address is required';
  if (!billing.city?.trim()) errors.billing_city = 'City is required';
  if (!billing.state?.trim()) errors.billing_state = 'State is required';
  if (!billing.postcode?.trim()) errors.billing_postcode = 'Postcode is required';
  if (!billing.country?.trim()) errors.billing_country = 'Country is required';
  if (!data.payment_method) errors.payment_method = 'Select a payment method';

  return errors;
}
