# East India Wills theme

[Bootstrap](https://www.drupal.org/project/bootstrap) subtheme, styled with the real [Bootstrap](https://getbootstrap.com/) framework (installed via composer as a dependency of the base theme).

## Development.

### CSS compilation.

Prerequisites: install [sass](https://sass-lang.com/install), and run `composer install` in `build/` so `build/vendor` (used by the Sass `@import`s) exists.

To compile, run from the subtheme directory: `sass src/scss/style.scss dist/css/style.css && sass src/scss/ck5style.scss dist/css/ck5style.css`

This is also done automatically on deploy via `vendor/bin/dockworker theme:build-all`, which additionally copies `src/js`, `src/img`, and `src/fonts` into `dist/` unmodified.
