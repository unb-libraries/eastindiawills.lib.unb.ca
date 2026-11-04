/**
 * @file
 * Route toggles for voyage maps (eiw-voyage-map.html.twig).
 *
 * Each legend checkbox shows/hides its route; a port is hidden once every
 * route calling there is hidden.
 */
(function (Drupal, once) {
  Drupal.behaviors.eiwVoyageMap = {
    attach(context) {
      once('eiw-voyage-map', '.eiw-voyage-map', context).forEach((map) => {
        const update = () => {
          const shown = new Set(
            [...map.querySelectorAll('.eiw-voyage-map__toggle:checked')]
              .map((input) => input.value),
          );
          map.querySelectorAll('[data-route]').forEach((el) => {
            el.classList.toggle('is-hidden', !shown.has(el.dataset.route));
          });
          map.querySelectorAll('[data-routes]').forEach((el) => {
            el.classList.toggle(
              'is-hidden',
              !el.dataset.routes.split(' ').some((r) => shown.has(r)),
            );
          });
        };
        map.addEventListener('change', update);
      });
    },
  };
})(Drupal, once);
