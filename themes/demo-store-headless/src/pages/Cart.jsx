/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { useNavigate } from 'react-router-dom';
import { useCart } from '../context/CartContext';
import '../styles/Cart.css';

function formatMinor(amount, totals) {
  const minor = totals.currency_minor_unit ?? 2;
  const symbol = totals.currency_symbol || '';
  const value = (Number(amount) / Math.pow(10, minor)).toFixed(minor);
  return `${symbol}${value}`;
}

function Cart() {
  const navigate = useNavigate();
  const { items, totals, loading, mutating, error, updateQuantity, removeFromCart, clearCart } = useCart();

  if (loading) {
    return (
      <div className="loading-state">
        <div className="spinner" />
        <p>Loading cart…</p>
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <div className="cart-page">
        <div className="cart-header">
          <h1>SHOPPING BAG</h1>
          <div className="title-divider" />
        </div>
        <div className="empty-cart">
          <p>Your shopping bag is empty</p>
          <button className="continue-shopping-btn" onClick={() => navigate('/shop')}>
            CONTINUE SHOPPING
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="cart-page">
      <div className="cart-header">
        <h1>SHOPPING BAG</h1>
        <div className="title-divider" />
        <p className="cart-count">{items.length} {items.length === 1 ? 'Item' : 'Items'}</p>
      </div>

      {error && <div className="error-banner">{error}</div>}

      <div className="cart-container">
        <div className="cart-items-section">
          {items.map((item) => {
            const image = item.images?.[0]?.src || 'https://via.placeholder.com/150x200?text=No+Image';
            const lineTotal = item.totals?.line_total;

            return (
              <div key={item.key} className="cart-item">
                <div className="cart-item-image">
                  <img src={image} alt={item.name} loading="lazy" />
                </div>

                <div className="cart-item-details">
                  <h3 className="cart-item-name">{item.name}</h3>
                  <p className="cart-item-price">{formatMinor(item.prices?.price, totals)}</p>

                  <div className="cart-item-quantity">
                    <label>QUANTITY</label>
                    <div className="quantity-controls">
                      <button
                        onClick={() => updateQuantity(item.key, item.quantity - 1)}
                        className="qty-btn"
                        disabled={mutating}
                      >
                        −
                      </button>
                      <span className="qty-value">{item.quantity}</span>
                      <button
                        onClick={() => updateQuantity(item.key, item.quantity + 1)}
                        className="qty-btn"
                        disabled={mutating}
                      >
                        +
                      </button>
                    </div>
                  </div>

                  <button
                    className="remove-btn"
                    onClick={() => removeFromCart(item.key)}
                    disabled={mutating}
                  >
                    REMOVE
                  </button>
                </div>

                <div className="cart-item-total">
                  <p>{formatMinor(lineTotal, totals)}</p>
                </div>
              </div>
            );
          })}
        </div>

        <div className="cart-summary-section">
          <div className="cart-summary">
            <h2>ORDER SUMMARY</h2>

            <div className="summary-line">
              <span>Subtotal</span>
              <span>{formatMinor(totals.total_items, totals)}</span>
            </div>

            <div className="summary-line">
              <span>Shipping</span>
              <span>
                {Number(totals.total_shipping) === 0
                  ? 'Free'
                  : formatMinor(totals.total_shipping, totals)}
              </span>
            </div>

            <div className="summary-divider" />

            <div className="summary-line total">
              <span>Total</span>
              <span>{formatMinor(totals.total_price, totals)}</span>
            </div>

            <button
              className="checkout-btn"
              onClick={() => navigate('/checkout')}
              disabled={mutating}
            >
              PROCEED TO CHECKOUT
            </button>

            <button className="continue-shopping-btn" onClick={() => navigate('/shop')}>
              CONTINUE SHOPPING
            </button>

            <button
              className="clear-cart-btn"
              onClick={clearCart}
              disabled={mutating}
            >
              CLEAR BAG
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default Cart;
