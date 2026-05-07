/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import '../styles/Footer.css';

function Footer() {
  const currentYear = new Date().getFullYear();
  const siteName = window.wpData?.siteName || 'Demo Store';

  return (
    <footer className="site-footer">
      <div className="footer-content">
        <p>© {currentYear} {siteName}</p>
      </div>
    </footer>
  );
}

export default Footer;
