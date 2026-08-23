/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { useEffect, useRef } from 'react';
import { Link } from 'react-router-dom';
const flavors = [
  { name: 'Voltage', note: 'Citrus ignition', color: 'cyan', image: '/wp-content/themes/demo-store-headless/public/energy/photos/voltage-can.jpg' },
  { name: 'Afterglow', note: 'Cherry zero', color: 'pink', image: '/wp-content/themes/demo-store-headless/public/energy/photos/afterglow-cans.jpg' },
  { name: 'Ion Rush', note: 'Lime charge', color: 'lime', image: '/wp-content/themes/demo-store-headless/public/energy/photos/ion-rush-can.jpg' },
];

function Home() {
  const siteName = 'PULSE';
  const heroRef = useRef(null);
  const videoRef = useRef(null);

  useEffect(() => {
    const syncVideoToScroll = () => {
      const hero = heroRef.current;
      const video = videoRef.current;
      if (!hero || !video || !Number.isFinite(video.duration)) return;
      const top = hero.getBoundingClientRect().top;
      const travel = Math.max(1, hero.offsetHeight);
      const progress = Math.min(1, Math.max(0, -top / travel));
      video.currentTime = Math.min(video.duration - 0.08, progress * video.duration);
    };

    window.addEventListener('scroll', syncVideoToScroll, { passive: true });
    videoRef.current?.addEventListener('loadedmetadata', syncVideoToScroll, { once: true });
    syncVideoToScroll();
    return () => window.removeEventListener('scroll', syncVideoToScroll);
  }, []);

  return (
    <div className="energy-home">
      <section className="pulse-hero" ref={heroRef}>
        <video ref={videoRef} className="hero-video" autoPlay muted loop playsInline preload="metadata" poster="/wp-content/themes/demo-store-headless/public/energy/photos/afterglow-cans.jpg" aria-hidden="true">
          <source src="/wp-content/themes/demo-store-headless/public/energy/video/neon-light-layer.mp4" type="video/mp4" />
        </video>
        <div className="hero-copy">
          <p className="signal-label"><span /> 180 MG / ZERO SUGAR</p>
          <h1>Stay<br /><em>charged.</em></h1>
          <p className="hero-intro">Bright flavor and clean energy for whatever happens next.</p>
          <div className="hero-actions">
            <Link to="/shop" className="btn btn-primary">Shop energy <b>↗</b></Link>
          </div>
        </div>
        <div className="hero-art" aria-hidden="true">
          <img src="/wp-content/themes/demo-store-headless/public/energy/photos/voltage-can.jpg" alt="" />
        </div>
        <Link to="/product/voltage-citrus-energy" className="hero-quick-card">
          <span>01 / CITRUS</span><strong>Voltage</strong><small>180 mg caffeine · 0 g sugar</small>
        </Link>
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
