const NAMESPACE = 'salve-deposit-simulation';

export function getDepositSimulation(source) {
  const simulation = source?.extensions?.[NAMESPACE];
  return simulation?.enabled ? simulation : null;
}
