/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { Link } from 'react-router-dom';
import ProductList from '../components/ProductList';
import '../styles/Home.css';

const HERO_VIDEO_URL = 'https://upload.wikimedia.org/wikipedia/commons/3/30/Cosmetics.webmhd.webm';
const HERO_VIDEO_SOURCE = 'https://commons.wikimedia.org/wiki/File:Cosmetics.webmhd.webm';

function Home() {
  const siteName = window.wpData?.siteName || 'Salve';
  return (
    <>
      <section className="home-hero" aria-labelledby="home-hero-title">
        <video
          className="home-hero-video"
          autoPlay
          muted
          loop
          playsInline
          preload="metadata"
          aria-hidden="true"
        >
          <source src={HERO_VIDEO_URL} type="video/webm" />
        </video>
        <div className="home-hero-overlay" />
        <div className="home-hero-content">
          <span className="home-hero-eyebrow">Considered cosmetics</span>
          <h1 id="home-hero-title">{siteName}</h1>
          <p>Quiet rituals and everyday formulas for your most considered self.</p>
          <Link to="/shop" className="btn home-hero-cta">Shop the collection</Link>
        </div>
        <p className="home-hero-attribution">
          Background film: <a href={HERO_VIDEO_SOURCE}>Cosmetics</a> by Sha’Boris, CC BY-SA 4.0
        </p>
      </section>
      <ProductList perPage={8} />
      <div style={{ textAlign: 'center', padding: 'var(--space-7) 0 var(--space-3)' }}>
        <Link to="/shop" className="btn btn-secondary">View all products</Link>
      </div>
    </>
  );
}

export default Home;
