/**
 * @file
 * confirm_submit module behaviors.
 */

(function($, Drupal, once) {
  /**
   * Click handler for confirmation popup.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.confirmModal = {
    attach(context) {
      function formSubmitModal(event) {
        if (
          event.originalEvent !== undefined &&
          event.originalEvent.isTrusted
        ) {
          const $submitter = event.originalEvent.target;
          if (
            $submitter.getAttribute('value') !== 'Preview' &&
            !event.altKey &&
            !event.ctrlKey &&
            !event.metaKey &&
            !event.shiftKey
          ) {
            const $confirmTitle = $($submitter).is('a')
              ? $submitter.text
              : $submitter.getAttribute('value');
            const $confirmDialog = $(
              '<div>'.concat(
                Drupal.theme('nodeConfirmModal', {
                  message: $submitter.getAttribute('data-confirm'),
                }),
                '</div>',
              ),
            ).appendTo('body');
            Drupal.dialog($confirmDialog, {
              buttons: [
                {
                  text: Drupal.t('Cancel'),
                  click: function click() {
                    $(this).dialog('close');
                  },
                },
                {
                  text: Drupal.t($confirmTitle),
                  click() {
                    if ($($submitter).is('a')) {
                      window.top.location.href = event.target.href;
                    } else {
                      $('#submit-after-confirmation--gin-edit-form').click();
                    }
                  },
                },
              ],
            }).showModal();
          }
        }
      }

      $('[data-confirm]')
        .off('click keypress')
        .on('click keypress', function(e) {
          e.preventDefault();
          formSubmitModal(e);
        });
    },
  };

  Drupal.theme.nodeConfirmModal = function($props) {
    return '<p>'.concat(Drupal.t($props.message), '</p>');
  };
})(jQuery, Drupal, once);
