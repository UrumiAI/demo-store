/**
 * SYNC: demo-store specific — do not port to base-headless.
 *
 * Dual-handle price range slider built from two native <input type="range">
 * elements. No external dependencies.
 */

import '../styles/PriceSlider.css';

function PriceSlider({
  min,
  max,
  currentMin,
  currentMax,
  currencySymbol = '$',
  onChange,
  onReset,
  disabled = false,
}) {
  const range = max - min || 1;
  const step = range < 10 ? 0.01 : 1;
  const midpoint = (max + min) / 2;
  const swap = currentMin > midpoint;
  const isNarrowed = currentMin !== min || currentMax !== max;

  // Percentage positions for the active range highlight
  const minPercent = ((currentMin - min) / range) * 100;
  const maxPercent = ((currentMax - min) / range) * 100;

  function handleMinChange(e) {
    const value = Number(e.target.value);
    onChange(Math.min(value, currentMax), currentMax);
  }

  function handleMaxChange(e) {
    const value = Number(e.target.value);
    onChange(currentMin, Math.max(value, currentMin));
  }

  function formatPrice(value) {
    if (step < 1) {
      return `${currencySymbol}${value.toFixed(2)}`;
    }
    return `${currencySymbol}${value}`;
  }

  return (
    <div
      className={`price-slider${disabled ? ' price-slider--disabled' : ''}`}
      role="group"
      aria-label="Price filter"
    >
      <span className="price-slider__label">Price Range</span>

      <div className="price-slider__track-wrapper">
        <div className="price-slider__track" />
        <div
          className="price-slider__range"
          style={{ left: `${minPercent}%`, width: `${maxPercent - minPercent}%` }}
        />
        <input
          type="range"
          className={`price-slider__input price-slider__input--min${swap ? ' price-slider__input--swap' : ''}`}
          min={min}
          max={max}
          step={step}
          value={currentMin}
          onChange={handleMinChange}
          aria-label="Minimum price"
          disabled={disabled}
        />
        <input
          type="range"
          className={`price-slider__input price-slider__input--max${swap ? ' price-slider__input--swap' : ''}`}
          min={min}
          max={max}
          step={step}
          value={currentMax}
          onChange={handleMaxChange}
          aria-label="Maximum price"
          disabled={disabled}
        />
      </div>

      <div className="price-slider__values">
        <span>{formatPrice(currentMin)}</span>
        <span>{formatPrice(currentMax)}</span>
      </div>

      {isNarrowed && onReset && (
        <button
          type="button"
          className="price-slider__reset"
          onClick={onReset}
          disabled={disabled}
        >
          Reset
        </button>
      )}
    </div>
  );
}

export default PriceSlider;
