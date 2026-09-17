/**
 * @file
 * Serial number capture: a scanner sends the code and Enter; Enter moves to
 * the next empty field instead of submitting, the last one submits.
 */
(function (Drupal) {
  Drupal.behaviors.hwdeskSerials = {
    attach(context) {
      const fields = Array.from(context.querySelectorAll('input.hwdesk-serial'));
      if (!fields.length) {
        return;
      }
      const first = fields.find((f) => !f.value) || fields[0];
      first.focus();
      fields.forEach((field, index) => {
        if (field.dataset.hwdeskBound) {
          return;
        }
        field.dataset.hwdeskBound = '1';
        field.addEventListener('keydown', (event) => {
          if (event.key !== 'Enter') {
            return;
          }
          const next = fields.slice(index + 1).find((f) => !f.value);
          if (next) {
            event.preventDefault();
            next.focus();
          }
        });
      });
    },
  };
})(Drupal);
