(() => {
  'use strict';

  if (!window.Chart) return;

  const themeRoot = document.body;
  const cssVar = (name) => getComputedStyle(themeRoot).getPropertyValue(name).trim();

  function applyAdminLteChartTheme() {
    const gridColor = 'rgba(148, 163, 184, 0.22)';

    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.color = '#64748b';
    Chart.defaults.borderColor = gridColor;
    Chart.defaults.plugins.legend.labels.color = '#334155';
    Object.assign(Chart.defaults.plugins.tooltip, {
      backgroundColor: cssVar('--bs-body-bg') || '#fff',
      borderColor: cssVar('--bs-border-color') || '#e2e8f0',
      borderWidth: 1,
      titleColor: '#0f172a',
      bodyColor: '#334155',
    });
    Chart.defaults.elements.arc.borderColor = cssVar('--bs-body-bg') || '#fff';
  }

  applyAdminLteChartTheme();
  new MutationObserver(() => {
    applyAdminLteChartTheme();
    Object.values(Chart.instances).forEach((chart) => chart.update('none'));
  }).observe(themeRoot, { attributes: true, attributeFilter: ['data-bs-theme'] });
})();
