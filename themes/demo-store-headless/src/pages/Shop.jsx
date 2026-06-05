/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 *
 * NOTE: The price filter (PriceSlider, getPriceRange, debounced price state)
 * is demo-store specific — do not port to base-headless.
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import ProductList from '../components/ProductList';
import PriceSlider from '../components/PriceSlider';
import { getPriceRange } from '../api/products';

function Shop() {
  const [priceRange, setPriceRange] = useState(null);
  const [selectedMin, setSelectedMin] = useState(null);
  const [selectedMax, setSelectedMax] = useState(null);
  const [debouncedMin, setDebouncedMin] = useState(null);
  const [debouncedMax, setDebouncedMax] = useState(null);

  const debounceRef = useRef(null);

  // Fetch price range on mount
  useEffect(() => {
    let cancelled = false;
    getPriceRange()
      .then((range) => {
        if (cancelled) return;
        setPriceRange(range);
        setSelectedMin(range.min);
        setSelectedMax(range.max);
        setDebouncedMin(range.min);
        setDebouncedMax(range.max);
      })
      .catch(() => {
        // Price range fetch failed — slider simply won't render
      });
    return () => { cancelled = true; };
  }, []);

  // Debounce selected values
  useEffect(() => {
    if (selectedMin === null || selectedMax === null) return;
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(() => {
      setDebouncedMin(selectedMin);
      setDebouncedMax(selectedMax);
    }, 300);
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, [selectedMin, selectedMax]);

  const handlePriceChange = useCallback((newMin, newMax) => {
    setSelectedMin(newMin);
    setSelectedMax(newMax);
  }, []);

  const handleReset = useCallback(() => {
    if (!priceRange) return;
    setSelectedMin(priceRange.min);
    setSelectedMax(priceRange.max);
    setDebouncedMin(priceRange.min);
    setDebouncedMax(priceRange.max);
  }, [priceRange]);

  const showSlider =
    priceRange !== null &&
    priceRange.min !== priceRange.max;

  // Determine if a price filter is actively narrowing results
  const isFiltered =
    priceRange !== null &&
    debouncedMin !== null &&
    debouncedMax !== null &&
    (debouncedMin !== priceRange.min || debouncedMax !== priceRange.max);

  // Only pass price params once range is loaded
  const filterMin = priceRange !== null ? debouncedMin : undefined;
  const filterMax = priceRange !== null ? debouncedMax : undefined;

  return (
    <>
      <section className="collection-title">
        <span className="eyebrow">Catalogue</span>
        <h1>Shop</h1>
        <div className="title-divider" />
      </section>

      {showSlider && (
        <PriceSlider
          min={priceRange.min}
          max={priceRange.max}
          currentMin={selectedMin}
          currentMax={selectedMax}
          currencySymbol={priceRange.currencySymbol}
          onChange={handlePriceChange}
          onReset={handleReset}
        />
      )}

      <ProductList
        perPage={24}
        minPrice={filterMin}
        maxPrice={filterMax}
        currencyMinorUnit={priceRange?.currencyMinorUnit}
        emptyMessage={
          isFiltered
            ? 'No products in this price range.'
            : 'No products yet.'
        }
      />
    </>
  );
}

export default Shop;
