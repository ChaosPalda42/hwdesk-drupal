/**
 * Dvě drobnosti, které stránky HW Desku potřebují: záložky na detailu zařízení
 * a hromadný výběr řádků v seznamu. Obojí funguje i bez JS — záložky jsou
 * odkazy na kotvy, hromadná lišta se bez zaškrtnutí nezobrazí.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.hwdeskTabs = {
    attach: function (context) {
      once('hwdesk-tabs', '.tabs', context).forEach(function (tabs) {
        var links = Array.prototype.slice.call(tabs.querySelectorAll('a[data-tab]'));
        var panels = Array.prototype.slice.call(document.querySelectorAll('.tab-panel'));
        function show(name, remember) {
          var panel = document.getElementById(name);
          if (!panel) { return; }
          links.forEach(function (l) { l.classList.toggle('active', l.dataset.tab === name); });
          panels.forEach(function (p) { p.classList.toggle('active', p === panel); });
          if (remember) { history.replaceState(null, '', '#' + name); }
        }
        links.forEach(function (link) {
          link.addEventListener('click', function (e) { e.preventDefault(); show(link.dataset.tab, true); });
        });
        if (window.location.hash) { show(window.location.hash.slice(1), false); }
      });
    }
  };

  Drupal.behaviors.hwdeskBulk = {
    attach: function (context) {
      once('hwdesk-bulk', '#bulkbar', context).forEach(function (bar) {
        var boxes = Array.prototype.slice.call(document.querySelectorAll('input.rowsel'));
        var all = document.getElementById('selall');
        var count = document.getElementById('bulkcount');
        function refresh() {
          var ids = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
          bar.classList.toggle('on', ids.length > 0);
          if (count) { count.textContent = ids.length; }
          document.querySelectorAll('input[name="ids"]').forEach(function (i) { i.value = ids.join(','); });
        }
        boxes.forEach(function (b) { b.addEventListener('change', refresh); });
        if (all) {
          all.addEventListener('change', function () {
            boxes.forEach(function (b) { b.checked = all.checked; });
            refresh();
          });
        }
        refresh();
      });
    }
  };
})(Drupal, once);
