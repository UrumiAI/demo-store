/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { Link } from 'react-router-dom';
const flavors = [
  { name: 'Voltage', note: 'Citrus ignition', color: 'cyan', image: '/wp-content/themes/demo-store-headless/public/energy/can-cyan.svg' },
  { name: 'Afterglow', note: 'Cherry zero', color: 'pink', image: '/wp-content/themes/demo-store-headless/public/energy/can-pink.svg' },
  { name: 'Ion Rush', note: 'Lime charge', color: 'lime', image: '/wp-content/themes/demo-store-headless/public/energy/can-lime.svg' },
];

function Home() {
  const siteName = 'PULSE';
  return (
    <div className="energy-home">
      <section className="pulse-hero">
        <div className="hero-orbit hero-orbit-one" />
        <div className="hero-orbit hero-orbit-two" />
        <div className="hero-copy">
          <p className="signal-label"><span /> ENERGY, REWIRED</p>
          <h1>Find your<br /><em>frequency.</em></h1>
          <p className="hero-intro">Clean energy for the hours that need more from you. Big flavor. Zero hesitation.</p>
          <div className="hero-actions">
            <Link to="/shop" className="btn btn-primary">Shop the drop <b>↗</b></Link>
            <a href="#flavors" className="hero-text-link">Pick a flavor <span>↓</span></a>
          </div>
          <div className="hero-stat-row">
            <div><strong>180</strong><span>mg caffeine</span></div>
            <div><strong>0</strong><span>g sugar</span></div>
            <div><strong>+ B</strong><span>vitamins</span></div>
          </div>
        </div>
        <div className="hero-art" aria-hidden="true">
          <img src="/wp-content/themes/demo-store-headless/public/energy/hero-cans.svg" alt="" />
          <span className="hero-badge">NEW<br />DROP<br /><i>01</i></span>
        </div>
        <p className="hero-side-label">PULSE / 2026 / ZERO LIMITS</p>
      </section>
      <section className="ticker" aria-label="Product benefits"><div>ZERO SUGAR <i>✦</i> BIG ENERGY <i>✦</i> ALL SIGNAL <i>✦</i> ZERO SUGAR <i>✦</i> BIG ENERGY <i>✦</i> ALL SIGNAL <i>✦</i></div></section>
      <section className="flavor-section" id="flavors">
        <div className="section-heading reveal-on-scroll">
          <p className="signal-label"><span /> SELECT A SIGNAL</p>
          <h2>Flavor with<br /><em>a pulse.</em></h2>
          <p>Three clean formulas. One very loud point of view.</p>
        </div>
        <div className="flavor-grid">
          {flavors.map((flavor, index) => (
            <Link to="/shop" className={`flavor-card flavor-${flavor.color} reveal-on-scroll delay-${index + 1}`} key={flavor.name}>
              <span className="flavor-number">0{index + 1}</span>
              <img src={flavor.image} alt={`${flavor.name} energy drink`} />
              <div><p>{flavor.note}</p><h3>{flavor.name}</h3><span>Explore flavor <b>↗</b></span></div>
            </Link>
          ))}
        </div>
      </section>
      <section className="signal-band reveal-on-scroll">
        <div className="signal-graphic">P<span>U</span>LSE</div>
        <div><p className="signal-label"><span /> BUILT FOR THE IN-BETWEEN</p><h2>More of what<br />moves you.</h2></div>
        <p>Made with nootropics, electrolytes, and a serious amount of get-up-and-go. No crash, no compromise.</p>
      </section>
    </div>
  );
}

export default Home;
