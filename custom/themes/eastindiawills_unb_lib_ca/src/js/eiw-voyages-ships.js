/**
 * @file
 * "Voyages and Ships" page (eiw-voyages-ships.html.twig).
 *
 * The voyages and ships lists sit side by side as two independent <ul>s,
 * with no shared row to attach a single :hover rule to. This mirrors a
 * hovered item's highlight onto the item at the same position in the
 * other list, so the page still reads as one two-column table.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.eiwVoyagesShips = {
    attach(context) {
      once('eiw-voyages-ships', '.eiw-voyages-ships', context).forEach((container) => {
        const lists = Array.from(container.querySelectorAll('.eiw-voyages-ships__list ul'));
        if (lists.length < 2) {
          return;
        }

        const setHighlight = (index, on) => {
          lists.forEach((list) => {
            const item = list.children[index];
            if (item) {
              item.classList.toggle('is-row-hover', on);
            }
          });
        };

        lists.forEach((list) => {
          Array.from(list.children).forEach((item, index) => {
            item.addEventListener('mouseenter', () => setHighlight(index, true));
            item.addEventListener('mouseleave', () => setHighlight(index, false));
          });
        });
      });
    },
  };
})(Drupal, once);
