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
  const siteName = window.wpData?.siteName || 'Demo Store';
  return (
    <>
      <section className="collection-title">
        <h2>{siteName}</h2>
        <div className="title-divider" />
      </section>
      <ProductList perPage={8} />
      <div style={{ textAlign: 'center', padding: '2rem 0' }}>
        <Link to="/shop">View all products →</Link>
      </div>
    </>
  );
}

export default Home;
