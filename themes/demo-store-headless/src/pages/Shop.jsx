/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import ProductList from '../components/ProductList';

function Shop() {
  return (
    <>
      <section className="collection-title">
        <span className="eyebrow">Charged to go</span>
        <h1>All flavors</h1>
        <div className="title-divider" />
      </section>
      <ProductList perPage={24} />
    </>
  );
}

export default Shop;
