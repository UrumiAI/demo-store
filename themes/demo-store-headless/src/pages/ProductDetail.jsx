/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { getProductBySlug } from '../api/products';
import { useCart } from '../context/CartContext';
import '../styles/ProductDetail.css';

function formatPrice(prices) {
  if (!prices?.price) return '';
  const minor = prices.currency_minor_unit ?? 2;
  const symbol = prices.currency_symbol || '';
  const value = (Number(prices.price) / Math.pow(10, minor)).toFixed(minor);
  return `${symbol}${value}`;
}

const shoeArtwork = {
  'aero-void': '/wp-content/themes/demo-store-headless/public/shoes/aero-void.jpg',
  'oxide-high': '/wp-content/themes/demo-store-headless/public/shoes/oxide-high.jpg',
  'monorail-02': '/wp-content/themes/demo-store-headless/public/shoes/monorail.jpg',
  'orbit-footwear-pack': '/wp-content/themes/demo-store-headless/public/shoes/oxide-high.jpg',
};

function ProductDetail() {
  const { slug } = useParams();
  const navigate = useNavigate();
  const { addToCart, mutating } = useCart();

  const [product, setProduct] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [quantity, setQuantity] = useState(1);
  const [selectedImageIndex, setSelectedImageIndex] = useState(0);
  const [addedToCart, setAddedToCart] = useState(false);
  const [actionError, setActionError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    getProductBySlug(slug)
      .then((data) => {
        if (cancelled) return;
        setProduct(data);
        setError(data ? null : 'Product not found');
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err.message || 'Failed to load product');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [slug]);

  const handleAddToCart = async () => {
    if (!product) return;
    if (addedToCart) {
      navigate('/cart');
      return;
    }
    setActionError(null);
    try {
      await addToCart(product, quantity);
      setAddedToCart(true);
    } catch (err) {
      setActionError(err.message || 'Failed to add to cart');
    }
  };

  const handleBuyNow = async () => {
    if (!product) return;
    setActionError(null);
    try {
      await addToCart(product, quantity);
      navigate('/cart');
    } catch (err) {
      setActionError(err.message || 'Failed to add to cart');
    }
  };

  if (loading) {
    return (
      <div className="loading-state">
        <div className="spinner" />
        <p>LOADING</p>
      </div>
    );
  }

  if (error || !product) {
    return (
      <div className="error-state">
        <p>{error || 'Product not found'}</p>
        <button className="back-btn" onClick={() => navigate('/shop')}>
          BACK TO SHOP
        </button>
      </div>
    );
  }

  const images = product.images || [];
  const currentImage = shoeArtwork[product.slug] || images[selectedImageIndex]?.src || 'https://via.placeholder.com/800x1000?text=No+Image';

  return (
    <div className="product-detail-page">
      <button className="back-link" onClick={() => navigate('/shop')}>
        ← BACK TO SHOP
      </button>

      <div className="product-detail-container">
        <div className="product-image-section">
          <div className="main-image-wrapper">
            <img src={currentImage} alt={product.name} className="main-product-image" loading="lazy" />
          </div>

          {images.length > 1 && (
            <div className="thumbnail-gallery">
              {images.map((image, index) => (
                <button
                  key={index}
                  type="button"
                  className={`thumbnail ${index === selectedImageIndex ? 'active' : ''}`}
                  onClick={() => setSelectedImageIndex(index)}
                >
                  <img src={image.src} alt={image.alt || `${product.name} – view ${index + 1}`} loading="lazy" />
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="product-info-section">
          <div className="product-header">
            <h1 className="product-title">{product.name}</h1>
            <div className="product-price-detail">{formatPrice(product.prices)}</div>
          </div>

          <div className="product-divider" />

          {product.short_description && (
            <div className="product-description"
              dangerouslySetInnerHTML={{ __html: product.short_description }} />
          )}

          {product.description && (
            <div className="product-description">
              <h3>DESCRIPTION</h3>
              <div dangerouslySetInnerHTML={{ __html: product.description }} />
            </div>
          )}

          <div className="quantity-selector">
            <label>QUANTITY</label>
            <div className="quantity-controls">
              <button onClick={() => setQuantity(Math.max(1, quantity - 1))} className="qty-btn">−</button>
              <span className="qty-value">{quantity}</span>
              <button onClick={() => setQuantity(quantity + 1)} className="qty-btn">+</button>
            </div>
          </div>

          {actionError && <div className="error-banner">{actionError}</div>}

          <div className="action-buttons">
            <button className="add-to-bag-btn" onClick={handleAddToCart} disabled={mutating}>
              {addedToCart ? 'PROCEED TO CART' : mutating ? 'ADDING…' : 'ADD TO BAG'}
            </button>
            <button className="buy-now-btn" onClick={handleBuyNow} disabled={mutating}>
              BUY NOW
            </button>
          </div>

          <div className="product-meta">
            {product.sku && (
              <div className="meta-item">
                <span className="meta-label">SKU:</span>
                <span className="meta-value">{product.sku}</span>
              </div>
            )}
            {product.categories?.length > 0 && (
              <div className="meta-item">
                <span className="meta-label">CATEGORY:</span>
                <span className="meta-value">
                  {product.categories.map((c) => c.name).join(', ')}
                </span>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default ProductDetail;
