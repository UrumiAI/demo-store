/**
 * SYNC: This file mirrors its counterpart in the sibling theme repo
 * (UrumiAI/base-headless ↔ UrumiAI/demo-store) at the same relative path.
 * Port improvements both ways. Identifier mapping when porting:
 *   localStorage key 'demo-store-cart-token' ↔ 'base-headless-cart-token'
 *   script handle    'demo-store-app'        ↔ 'base-headless-app'
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import {
  getCart,
  addCartItem,
  updateCartItem,
  removeCartItem,
  clearCartItems,
  updateCartCustomer,
} from '../api/cart';

const CartContext = createContext(null);

function emptyCart() {
  return {
    items: [],
    items_count: 0,
    totals: {
      total_items: '0',
      total_price: '0',
      total_shipping: '0',
      currency_code: 'USD',
      currency_minor_unit: 2,
      currency_symbol: '$',
    },
  };
}

export function CartProvider({ children }) {
  const [cart, setCart] = useState(() => emptyCart());
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [mutating, setMutating] = useState(false);

  const refreshCart = useCallback(async () => {
    try {
      const data = await getCart();
      setCart(data || emptyCart());
      setError(null);
    } catch (err) {
      console.error('Failed to load cart', err);
      setError(err.message || 'Failed to load cart');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refreshCart();
  }, [refreshCart]);

  const wrapMutation = useCallback(async (fn) => {
    setMutating(true);
    setError(null);
    try {
      const updated = await fn();
      if (updated) {
        setCart(updated);
      } else {
        await refreshCart();
      }
      return updated;
    } catch (err) {
      console.error('Cart mutation failed', err);
      setError(err.message || 'Cart update failed');
      throw err;
    } finally {
      setMutating(false);
    }
  }, [refreshCart]);

  const addToCart = useCallback((product, quantity = 1) => {
    return wrapMutation(() => addCartItem({ id: product.id, quantity }));
  }, [wrapMutation]);

  const updateQuantity = useCallback((key, quantity) => {
    if (quantity <= 0) {
      return wrapMutation(() => removeCartItem({ key }));
    }
    return wrapMutation(() => updateCartItem({ key, quantity }));
  }, [wrapMutation]);

  const removeFromCart = useCallback((key) => {
    return wrapMutation(() => removeCartItem({ key }));
  }, [wrapMutation]);

  const clearCart = useCallback(() => {
    return wrapMutation(async () => {
      await clearCartItems();
      return emptyCart();
    });
  }, [wrapMutation]);

  const updateCustomer = useCallback((customer) => {
    return wrapMutation(() => updateCartCustomer(customer));
  }, [wrapMutation]);

  const value = useMemo(() => ({
    cart,
    items: cart.items,
    itemCount: cart.items_count,
    totals: cart.totals,
    loading,
    mutating,
    error,
    refreshCart,
    addToCart,
    updateQuantity,
    removeFromCart,
    clearCart,
    updateCustomer,
  }), [cart, loading, mutating, error, refreshCart, addToCart, updateQuantity, removeFromCart, clearCart, updateCustomer]);

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const context = useContext(CartContext);
  if (!context) {
    throw new Error('useCart must be used within CartProvider');
  }
  return context;
}
