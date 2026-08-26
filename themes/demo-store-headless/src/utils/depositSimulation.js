const OXIDE_HIGH_SKU = 'ORB-OXIDE-01';

export function getDepositSimulation(items = []) {
  const depositItems = items.filter((item) => item?.sku === OXIDE_HIGH_SKU);
  if (depositItems.length === 0) return null;

  const depositDueNow = depositItems.reduce(
    (total, item) => total + Number(item.totals?.line_total || 0),
    0
  );

  return {
    enabled: depositDueNow > 0,
    deposit_due_now: depositDueNow,
    balance_due: depositDueNow,
    full_unit_price: Number(depositItems[0]?.prices?.price || 0) * 2,
  };
}
