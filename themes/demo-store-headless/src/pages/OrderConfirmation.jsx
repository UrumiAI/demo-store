/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { getOrder } from '../api/checkout';
import '../styles/OrderConfirmation.css';

function formatAmount(amount, currency) {
  if (amount === undefined || amount === null) return '';
  const minor = currency?.minor_unit ?? 2;
  const symbol = currency?.symbol || '';
  const value = (Number(amount) / Math.pow(10, minor)).toFixed(minor);
  return `${symbol}${value}`;
}

function getStoredOrder(orderId) {
  try {
    const stored = sessionStorage.getItem(`demo-store-order-${orderId}`);
    return stored ? JSON.parse(stored) : null;
  } catch {
    return null;
  }
}

function OrderConfirmation() {
  const { orderId } = useParams();
  const [searchParams] = useSearchParams();
  const orderKey = searchParams.get('key');
  const location = useLocation();
  const navigate = useNavigate();
  const [order, setOrder] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    if (!orderId) return;
    let cancelled = false;
    const initialOrder = location.state?.order || getStoredOrder(orderId);

    if (initialOrder) {
      setOrder(initialOrder);
      setError(null);
      setLoading(false);
      return () => { cancelled = true; };
    }

    setLoading(true);
    getOrder(orderId, orderKey)
      .then((data) => {
        if (cancelled) return;
        setOrder(data);
        setError(null);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message || 'Failed to load order');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [location.state, orderId, orderKey]);

  if (loading) {
    return (
      <div className="loading-state">
        <div className="spinner" />
        <p>LOADING ORDER DETAILS</p>
      </div>
    );
  }

  if (error || !order) {
    return (
      <div className="error-state">
        <p>{error || 'Order not found'}</p>
        <button className="back-btn" onClick={() => navigate('/shop')}>
          BACK TO SHOP
        </button>
      </div>
    );
  }

  const currency = {
    minor_unit: order.totals?.currency_minor_unit ?? 2,
    symbol: order.totals?.currency_symbol || '',
  };
  const billing = order.billing_address || {};
  const shipping = order.shipping_address || billing;

  return (
    <div className="order-confirmation-page">
      <div className="confirmation-header">
        <h1>THANK YOU FOR YOUR ORDER</h1>
        <p className="order-number">Order #{order.order_number || order.order_id}</p>
        <div className="title-divider" />
      </div>

      <div className="confirmation-container">
        <div className="confirmation-message">
          <p>Your order has been received. We'll send a confirmation email shortly.</p>
        </div>

        <div className="order-details-grid">
          <div className="detail-section">
            <h3>ORDER INFORMATION</h3>
            <div className="detail-item">
              <span className="label">Status:</span>
              <span className="value status">{(order.status || '').toUpperCase()}</span>
            </div>
            <div className="detail-item">
              <span className="label">Payment Method:</span>
              <span className="value">{order.payment_method_title || order.payment_method || ''}</span>
            </div>
          </div>

          <div className="detail-section">
            <h3>BILLING ADDRESS</h3>
            <div className="address">
              <p>{billing.first_name} {billing.last_name}</p>
              <p>{billing.address_1}</p>
              {billing.address_2 && <p>{billing.address_2}</p>}
              <p>{billing.city}, {billing.state} {billing.postcode}</p>
              <p>{billing.country}</p>
              {billing.email && <p className="contact-info">{billing.email}</p>}
              {billing.phone && <p className="contact-info">{billing.phone}</p>}
            </div>
          </div>

          <div className="detail-section">
            <h3>SHIPPING ADDRESS</h3>
            <div className="address">
              <p>{shipping.first_name} {shipping.last_name}</p>
              <p>{shipping.address_1}</p>
              {shipping.address_2 && <p>{shipping.address_2}</p>}
              <p>{shipping.city}, {shipping.state} {shipping.postcode}</p>
              <p>{shipping.country}</p>
            </div>
          </div>
        </div>

        <div className="order-items-section">
          <h3>ORDER ITEMS</h3>
          <div className="order-items-table">
            <div className="table-header">
              <span>Product</span>
              <span>Quantity</span>
              <span>Total</span>
            </div>
            {(order.items || []).map((item) => (
              <div key={item.key || item.id} className="table-row">
                <span className="item-name">{item.name}</span>
                <span className="item-qty">× {item.quantity}</span>
                <span className="item-total">
                  {formatAmount(item.totals?.line_total, currency)}
                </span>
              </div>
            ))}
          </div>

          <div className="order-totals">
            <div className="total-line">
              <span>Subtotal:</span>
              <span>{formatAmount(order.totals?.total_items, currency)}</span>
            </div>
            <div className="total-line">
              <span>Shipping:</span>
              <span>{formatAmount(order.totals?.total_shipping, currency)}</span>
            </div>
            <div className="total-line grand-total">
              <span>Total:</span>
              <span>{formatAmount(order.totals?.total_price, currency)}</span>
            </div>
          </div>
        </div>

        <div className="confirmation-actions">
          <button className="continue-btn" onClick={() => navigate('/shop')}>
            CONTINUE SHOPPING
          </button>
        </div>
      </div>
    </div>
  );
}

export default OrderConfirmation;
