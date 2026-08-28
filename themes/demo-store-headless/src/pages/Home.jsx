/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 */

import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';

const shoes = [
  {
    name: 'Aero Void',
    type: 'Runner / 001',
    price: '$180',
    previousPrice: '$210',
    image: '/wp-content/themes/demo-store-headless/public/shoes/aero-void.jpg',
    slug: 'aero-void-runner',
    copy: 'A low-gravity runner for city miles, late nights, and every fast exit.',
    color: 'Ivory mesh / bone',
  },
  {
    name: 'Oxide High',
    type: 'High-top / 002',
    price: '$210',
    previousPrice: '$245',
    image: '/wp-content/themes/demo-store-headless/public/shoes/oxide-high.jpg',
    slug: 'oxide-high',
    copy: 'Layered color, elevated collar, and a silhouette that refuses to stand still.',
    color: 'Electric teal / black',
  },
  {
    name: 'Monorail 02',
    type: 'Low-top / 003',
    price: '$165',
    previousPrice: '$190',
    image: '/wp-content/themes/demo-store-headless/public/shoes/monorail.jpg',
    slug: 'monorail-02',
    copy: 'A clean, cushioned everyday pair designed to make the regular route feel new.',
    color: 'Cocoa / sail',
  },
];

function Home() {
  const [activeShoe, setActiveShoe] = useState(0);
  const shoe = shoes[activeShoe];

  const nextShoe = () => setActiveShoe((current) => (current + 1) % shoes.length);

  useEffect(() => {
    const timer = window.setInterval(nextShoe, 7000);
    return () => window.clearInterval(timer);
  }, []);

  return (
    <div className="orbital-home">
      <section className="orbital-intro-video" aria-label="ORBITAL motion film">
        <video autoPlay muted loop playsInline preload="metadata" poster="/wp-content/themes/demo-store-headless/public/shoes/oxide-high.jpg" aria-hidden="true">
          <source src="/wp-content/themes/demo-store-headless/public/shoes/orbital-motion.mp4" type="video/mp4" />
        </video>
        <p><span>ORBITAL</span><b>01 / MOTION FILM</b></p>
      </section>
      <section className="shoe-showcase" aria-label="Featured footwear">
        <video className="shoe-hero-video" autoPlay muted loop playsInline preload="metadata" poster="/wp-content/themes/demo-store-headless/public/shoes/oxide-high.jpg" aria-hidden="true">
          <source src="/wp-content/themes/demo-store-headless/public/shoes/orbital-motion.mp4" type="video/mp4" />
        </video>
        <div className="shoe-orbit shoe-orbit-one" aria-hidden="true" />
        <div className="shoe-orbit shoe-orbit-two" aria-hidden="true" />
        <div className="shoe-showcase-copy">
          <p className="shoe-kicker">ORBITAL / FOOTWEAR SYSTEMS</p>
          <h1>Move<br />like the<br /><em>future.</em></h1>
          <p>{shoe.copy}</p>
          <Link to="/shop" className="shoe-shop-link">Explore collection <span>↗</span></Link>
          <button type="button" className="shoe-next" onClick={nextShoe} aria-label="Show next shoe">
            <span>Next shoe</span><b>→</b>
          </button>
        </div>

        <div className="shoe-stage" aria-live="polite">
          <div className="shoe-stage-glow" aria-hidden="true" />
          <img key={shoe.slug} className="shoe-render" src={shoe.image} alt={`${shoe.name} sneaker`} />
          <div className="shoe-stage-meta"><span>{String(activeShoe + 1).padStart(2, '0')}</span><span>/ 03</span></div>
        </div>

        <aside className="shoe-buybox">
          <p>{shoe.type}</p>
          <h2>{shoe.name}</h2>
          <div className="shoe-price"><strong>{shoe.price}</strong><del>{shoe.previousPrice}</del></div>
          <div className="shoe-colour"><span>Color</span><b>{shoe.color}</b></div>
          <div className="shoe-sizes" aria-label="Available sizes"><span>EU</span><i>38</i><i>40</i><i>42</i><i>44</i></div>
          <Link to={`/product/${shoe.slug}`} className="shoe-detail-link">View shoe <span>↗</span></Link>
        </aside>

        <p className="shoe-manifesto">Confidence, engineered for motion.</p>
      </section>

      <section className="shoe-cards" aria-label="The launch collection">
        {shoes.map((item, index) => (
          <button key={item.slug} type="button" onClick={() => setActiveShoe(index)} className={index === activeShoe ? 'shoe-card is-active' : 'shoe-card'}>
            <img src={item.image} alt="" />
            <span>{item.name}</span><b>{String(index + 1).padStart(2, '0')}</b>
          </button>
        ))}
      </section>
    </div>
  );
}

export default Home;
