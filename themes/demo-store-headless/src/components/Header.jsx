/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { Link } from 'react-router-dom';
import { useCart } from '../context/CartContext';
import '../styles/Header.css';

function Header() {
  const { itemCount } = useCart();
  const siteName = 'PULSE';

  return (
    <header className="site-header">
      <div className="header-content">
        <Link to="/" className="site-logo">
          <span className="logo-text">{siteName}</span>
        </Link>
        <nav className="site-nav">
          <Link to="/shop" className="nav-link">Shop</Link>
          <Link to="/cart" className="nav-link cart-link">
            Cart{itemCount > 0 && <span className="cart-count">({itemCount})</span>}
          </Link>
        </nav>
      </div>
    </header>
  );
}

export default Header;
