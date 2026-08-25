/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useCart } from '../context/CartContext';
import { getCheckout, submitCheckout, validateCheckoutForm } from '../api/checkout';
import '../styles/Checkout.css';

function emptyAddress() {
  return {
    first_name: '',
    last_name: '',
    company: '',
    address_1: '',
    address_2: '',
    city: '',
    state: '',
    postcode: '',
    country: 'US',
    email: '',
    phone: '',
  };
}

function formatMinor(amount, totals) {
  if (amount === undefined || amount === null) return '';
  const minor = totals.currency_minor_unit ?? 2;
  const symbol = totals.currency_symbol || '';
  const value = (Number(amount) / Math.pow(10, minor)).toFixed(minor);
  return `${symbol}${value}`;
}

function paymentMethodLabel(method) {
  const labels = {
    bacs: 'Direct bank transfer',
    cod: 'Cash on delivery',
    cheque: 'Check payments',
    paypal: 'PayPal',
  };
  return labels[method] || method.replace(/[-_]+/g, ' ');
}

function hasShippingRates(cart) {
  return (cart?.shipping_rates || []).some(
    (shippingPackage) => (shippingPackage.shipping_rates || []).length > 0
  );
}

function shippingLabel(cart, totals) {
  if (cart?.needs_shipping && !hasShippingRates(cart)) {
    return 'Calculated at checkout';
  }
  return Number(totals.total_shipping) === 0
    ? 'Free'
    : formatMinor(totals.total_shipping, totals);
}

function Checkout() {
  const navigate = useNavigate();
  const { cart, items, totals, loading: cartLoading, refreshCart, updateCustomer } = useCart();

  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState({});
  const [paymentMethods, setPaymentMethods] = useState([]);
  const [shipToDifferent, setShipToDifferent] = useState(false);
  const [billing, setBilling] = useState(emptyAddress());
  const [shipping, setShipping] = useState(emptyAddress());
  const [paymentMethod, setPaymentMethod] = useState('');
  const [customerNote, setCustomerNote] = useState('');

  useEffect(() => {
    if (!cartLoading && items.length === 0) {
      navigate('/cart');
    }
  }, [cartLoading, items.length, navigate]);

  useEffect(() => {
    let cancelled = false;
    getCheckout()
      .then((data) => {
        if (cancelled || !data) return;
        const available = data.payment_methods || [];
        setPaymentMethods(available);
        if (available.length > 0) {
          setPaymentMethod((current) => current || available[0]);
        }
        if (data.billing_address) {
          setBilling((prev) => ({ ...prev, ...data.billing_address }));
        }
        if (data.shipping_address) {
          setShipping((prev) => ({ ...prev, ...data.shipping_address }));
        }
      })
      .catch((err) => {
        console.error('Failed to load checkout', err);
      });
    return () => { cancelled = true; };
  }, []);

  const handleBillingChange = (field, value) => {
    setBilling((prev) => ({ ...prev, [field]: value }));
    setErrors((prev) => {
      if (!prev[`billing_${field}`]) return prev;
      const next = { ...prev };
      delete next[`billing_${field}`];
      return next;
    });
  };

  const handleShippingChange = (field, value) => {
    setShipping((prev) => ({ ...prev, [field]: value }));
  };

  const payload = useMemo(() => ({
    billing_address: billing,
    shipping_address: shipToDifferent ? shipping : billing,
    payment_method: paymentMethod,
    order_notes: customerNote,
  }), [billing, shipping, shipToDifferent, paymentMethod, customerNote]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    const validationErrors = validateCheckoutForm(payload);
    if (Object.keys(validationErrors).length > 0) {
      setErrors(validationErrors);
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    setSubmitting(true);
    setErrors({});
    try {
      const updatedCart = await updateCustomer({
        billing_address: billing,
        shipping_address: shipToDifferent ? shipping : billing,
      });
      const updatedPaymentMethods = updatedCart?.payment_methods || [];
      const selectedPaymentMethod = updatedPaymentMethods.includes(paymentMethod)
        ? paymentMethod
        : updatedPaymentMethods[0];

      setPaymentMethods(updatedPaymentMethods);
      setPaymentMethod(selectedPaymentMethod || '');

      if (updatedCart?.needs_shipping && !hasShippingRates(updatedCart)) {
        throw new Error('No delivery option is available for this address.');
      }
      if (!selectedPaymentMethod) {
        throw new Error('No payment method is available for this order.');
      }

      const order = await submitCheckout({
        ...payload,
        payment_method: selectedPaymentMethod,
      });
      await refreshCart();
      const orderKey = order?.order_key ? `?key=${encodeURIComponent(order.order_key)}` : '';
      navigate(`/order-confirmation/${order.order_id}${orderKey}`);
    } catch (err) {
      setErrors({ submit: err.message || 'Checkout failed. Please try again.' });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } finally {
      setSubmitting(false);
    }
  };

  if (cartLoading || items.length === 0) {
    return (
      <div className="loading-state">
        <div className="spinner" />
        <p>Loading checkout…</p>
      </div>
    );
  }

  return (
    <div className="checkout-page">
      <div className="checkout-header">
        <h1>CHECKOUT</h1>
        <div className="title-divider" />
      </div>

      {errors.submit && <div className="error-banner">{errors.submit}</div>}

      <form className="checkout-form" onSubmit={handleSubmit}>
        <div className="checkout-container">
          <div className="checkout-forms-section">
            <section className="checkout-section">
              <h2>BILLING DETAILS</h2>

              <div className="checkout-field-row">
                <Field label="FIRST NAME *" error={errors.billing_first_name}>
                  <input type="text" value={billing.first_name}
                    onChange={(e) => handleBillingChange('first_name', e.target.value)} />
                </Field>
                <Field label="LAST NAME *" error={errors.billing_last_name}>
                  <input type="text" value={billing.last_name}
                    onChange={(e) => handleBillingChange('last_name', e.target.value)} />
                </Field>
              </div>

              <Field label="EMAIL ADDRESS *" error={errors.billing_email}>
                <input type="email" value={billing.email}
                  onChange={(e) => handleBillingChange('email', e.target.value)} />
              </Field>

              <Field label="PHONE *" error={errors.billing_phone}>
                <input type="tel" value={billing.phone}
                  onChange={(e) => handleBillingChange('phone', e.target.value)} />
              </Field>

              <Field label="ADDRESS *" error={errors.billing_address_1}>
                <input type="text" placeholder="Street address" value={billing.address_1}
                  onChange={(e) => handleBillingChange('address_1', e.target.value)} />
              </Field>

              <div className="form-field">
                <input type="text" placeholder="Apartment, suite, etc. (optional)"
                  value={billing.address_2}
                  onChange={(e) => handleBillingChange('address_2', e.target.value)} />
              </div>

              <div className="checkout-field-row">
                <Field label="CITY *" error={errors.billing_city}>
                  <input type="text" value={billing.city}
                    onChange={(e) => handleBillingChange('city', e.target.value)} />
                </Field>
                <Field label="STATE *" error={errors.billing_state}>
                  <input type="text" value={billing.state}
                    onChange={(e) => handleBillingChange('state', e.target.value)} />
                </Field>
              </div>

              <div className="checkout-field-row">
                <Field label="POSTCODE *" error={errors.billing_postcode}>
                  <input type="text" value={billing.postcode}
                    onChange={(e) => handleBillingChange('postcode', e.target.value)} />
                </Field>
                <Field label="COUNTRY *" error={errors.billing_country}>
                  <select value={billing.country}
                    onChange={(e) => handleBillingChange('country', e.target.value)}>
                    <option value="IN">India</option>
                    <option value="US">United States</option>
                    <option value="GB">United Kingdom</option>
                    <option value="AU">Australia</option>
                  </select>
                </Field>
              </div>
            </section>

            <section className="checkout-section">
              <label className="ship-different-toggle">
                <input
                  type="checkbox"
                  checked={shipToDifferent}
                  onChange={(e) => setShipToDifferent(e.target.checked)}
                />
                Ship to a different address
              </label>

              {shipToDifferent && (
                <>
                  <div className="checkout-field-row">
                    <Field label="FIRST NAME">
                      <input type="text" value={shipping.first_name}
                        onChange={(e) => handleShippingChange('first_name', e.target.value)} />
                    </Field>
                    <Field label="LAST NAME">
                      <input type="text" value={shipping.last_name}
                        onChange={(e) => handleShippingChange('last_name', e.target.value)} />
                    </Field>
                  </div>
                  <Field label="ADDRESS">
                    <input type="text" value={shipping.address_1}
                      onChange={(e) => handleShippingChange('address_1', e.target.value)} />
                  </Field>
                  <div className="checkout-field-row">
                    <Field label="CITY">
                      <input type="text" value={shipping.city}
                        onChange={(e) => handleShippingChange('city', e.target.value)} />
                    </Field>
                    <Field label="POSTCODE">
                      <input type="text" value={shipping.postcode}
                        onChange={(e) => handleShippingChange('postcode', e.target.value)} />
                    </Field>
                  </div>
                </>
              )}
            </section>

            <section className="checkout-section">
              <h2>ORDER NOTES (OPTIONAL)</h2>
              <div className="form-field">
                <textarea
                  rows="4"
                  placeholder="Notes about your order, e.g. special delivery instructions"
                  value={customerNote}
                  onChange={(e) => setCustomerNote(e.target.value)}
                />
              </div>
            </section>
          </div>

          <div className="checkout-summary-section">
            <div className="order-summary">
              <h2>YOUR ORDER</h2>

              <div className="order-items">
                {items.map((item) => (
                  <div key={item.key} className="order-item">
                    <div className="item-details">
                      <span className="item-name">{item.name}</span>
                      <span className="item-qty">× {item.quantity}</span>
                    </div>
                    <span className="item-total">{formatMinor(item.totals?.line_total, totals)}</span>
                  </div>
                ))}
              </div>

              <div className="order-totals">
                <div className="total-line">
                  <span>Subtotal</span>
                  <span>{formatMinor(totals.total_items, totals)}</span>
                </div>
                <div className="total-line">
                  <span>Shipping</span>
                  <span>
                    {shippingLabel(cart, totals)}
                  </span>
                </div>
                <div className="total-line grand-total">
                  <span>Total</span>
                  <span>{formatMinor(totals.total_price, totals)}</span>
                </div>
              </div>

              <div className="payment-methods">
                <h3>PAYMENT METHOD</h3>
                {paymentMethods.length === 0 && (
                  <p className="payment-empty">No enabled payment methods. Configure one in WooCommerce → Settings → Payments.</p>
                )}
                {paymentMethods.map((method) => (
                  <label key={method} className="payment-option">
                    <input
                      type="radio"
                      name="payment_method"
                      value={method}
                      checked={paymentMethod === method}
                      onChange={(e) => setPaymentMethod(e.target.value)}
                    />
                    <span>{paymentMethodLabel(method)}</span>
                  </label>
                ))}
                {errors.payment_method && (
                  <span className="field-error">{errors.payment_method}</span>
                )}
              </div>

              <button type="submit" className="place-order-btn" disabled={submitting}>
                {submitting ? 'PROCESSING…' : 'PLACE ORDER'}
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  );
}

function Field({ label, error, children }) {
  return (
    <div className="form-field">
      {label && <label>{label}</label>}
      {children}
      {error && <span className="field-error">{error}</span>}
    </div>
  );
}

export default Checkout;
