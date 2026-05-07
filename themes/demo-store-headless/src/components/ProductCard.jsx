/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { Link } from 'react-router-dom';
import '../styles/ProductCard.css';

function pickPrice(product) {
  const prices = product.prices || {};
  if (prices.price) {
    const symbol = prices.currency_symbol || '$';
    const minorUnit = prices.currency_minor_unit ?? 2;
    const value = (Number(prices.price) / Math.pow(10, minorUnit)).toFixed(minorUnit);
    return `${symbol}${value}`;
  }
  return '';
}

function ProductCard({ product }) {
  const image = product.images?.[0]?.src || 'https://via.placeholder.com/400x400?text=No+Image';
  const slug = product.slug || product.id;

  return (
    <Link to={`/product/${slug}`} className="product-card">
      <div className="product-image-wrapper">
        <img src={image} alt={product.name} loading="lazy" />
      </div>
      <div className="product-info">
        <h3 className="product-name">{product.name}</h3>
        <div className="product-price">{pickPrice(product)}</div>
      </div>
    </Link>
  );
}

export default ProductCard;
