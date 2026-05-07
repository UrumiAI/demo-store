/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { storeApiRequest } from './storeApi';

export function getCheckout() {
  return storeApiRequest('checkout');
}

export function submitCheckout(payload) {
  return storeApiRequest('checkout', {
    method: 'POST',
    body: payload,
  });
}

/**
 * Order details fetched by id + key. The Store API uses a `key` query param
 * (the order's view key) instead of authentication for guest order lookups.
 */
export function getOrder(orderId, orderKey) {
  return storeApiRequest(`order/${orderId}`, {
    query: { key: orderKey },
  });
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
