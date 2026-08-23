/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { Link } from 'react-router-dom';
import '../styles/Footer.css';

function PlaceholderLink({ children }) {
  return (
    <a href="#" onClick={(e) => e.preventDefault()}>{children}</a>
  );
}

function Footer() {
  const currentYear = new Date().getFullYear();
  const siteName = 'PULSE';

  return (
    <footer className="site-footer">
      <div className="footer-content">
        <div className="footer-brand">
          <Link to="/" className="footer-logo">{siteName}</Link>
          <p className="footer-tagline">
            Energy for the in-between. Find your frequency.
          </p>
        </div>

        <div className="footer-column">
          <h4>Shop</h4>
          <nav>
            <Link to="/shop">All products</Link>
            <PlaceholderLink>Variety packs</PlaceholderLink>
            <PlaceholderLink>Subscription</PlaceholderLink>
            <PlaceholderLink>Store locator</PlaceholderLink>
          </nav>
        </div>

        <div className="footer-column">
          <h4>About</h4>
          <nav>
            <PlaceholderLink>Our story</PlaceholderLink>
            <PlaceholderLink>Journal</PlaceholderLink>
            <PlaceholderLink>Stockists</PlaceholderLink>
          </nav>
        </div>

        <div className="footer-column">
          <h4>Help</h4>
          <nav>
            <PlaceholderLink>Contact</PlaceholderLink>
            <PlaceholderLink>Shipping</PlaceholderLink>
            <PlaceholderLink>Returns</PlaceholderLink>
          </nav>
        </div>
      </div>

      <div className="footer-bottom">
        <span>© {currentYear} {siteName}. All rights reserved.</span>
        <a href="https://freestockfootagearchive.com/neon-light-layer-effect-abstract-night/" target="_blank" rel="noreferrer">Neon loop: Free Stock Footage Archive · CC BY 4.0</a>
      </div>
    </footer>
  );
}

export default Footer;
