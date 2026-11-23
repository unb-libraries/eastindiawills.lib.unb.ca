/**
 * @file
 * "Cite this record" box on will pages (node--eiw-will--full.html.twig).
 *
 * Adds today's date as the access date (done here rather than in the
 * template, so cached pages don't carry a stale date), and shows a Copy
 * button where the browser can write to the clipboard.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.eiwCitation = {
    attach(context) {
      once('eiw-citation', '.eiw-citation', context).forEach((box) => {
        const text = box.querySelector('.eiw-citation__text');
        const accessed = box.querySelector('.eiw-citation__accessed');
        const button = box.querySelector('.eiw-citation__copy');
        const status = box.querySelector('.eiw-citation__status');

        if (accessed) {
          const today = new Date().toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
          });
          accessed.textContent = Drupal.t(', accessed @date', { '@date': today });
          accessed.hidden = false;
        }

        if (!button || !text || !navigator.clipboard) {
          return;
        }
        button.hidden = false;
        button.addEventListener('click', () => {
          const citation = text.textContent.replace(/\s+/g, ' ').trim();
          navigator.clipboard.writeText(citation).then(
            () => {
              button.textContent = Drupal.t('Copied');
              status.textContent = Drupal.t('Citation copied to the clipboard.');
              setTimeout(() => {
                button.textContent = Drupal.t('Copy citation');
              }, 2000);
            },
            () => {
              status.textContent = Drupal.t('The citation could not be copied. Select the text to copy it.');
            },
          );
        });
      });
    },
  };
})(Drupal, once);
