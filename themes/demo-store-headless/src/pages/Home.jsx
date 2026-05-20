/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { Link } from 'react-router-dom';
import ProductList from '../components/ProductList';

function Home() {
  const siteName = window.wpData?.siteName || 'Salve';
  return (
    <>
      <section className="collection-title">
        <span className="eyebrow">Value Cosmetic</span>
        <h1>{siteName}</h1>
        <div className="title-divider" />
      </section>
      <ProductList perPage={8} />
      <div style={{ textAlign: 'center', padding: 'var(--space-7) 0 var(--space-3)' }}>
        <Link to="/shop" className="btn btn-secondary">View all products</Link>
      </div>
    </>
  );
}

export default Home;
