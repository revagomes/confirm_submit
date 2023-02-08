<?php

namespace Drupal\ejp_content\Plugin\FormAlter;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\pluginformalter\Plugin\FormAlterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Node content form alter.
 *
 * @FormAlter(
 *   id = "ejp_content_node_form_alter",
 *   label = @Translation("Alters node content form."),
 *   form_id = {
 *    "node_ejp_ejn_form",
 *    "node_ejp_atlas_form",
 *    "node_ejp_ejn_edit_form",
 *    "node_ejp_atlas_edit_form",
 *   },
 * )
 *
 * @package Drupal\ejp_content\Plugin\FormAlter
 */
class NodeContentFormAlter extends FormAlterBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a CurrentDateExtraField object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): NodeContentFormAlter {
    $current_user = $container->get('current_user');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $current_user
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$form, FormStateInterface &$form_state, $form_id) {
    if (!str_contains($form_id, 'edit_form')) {
      // Disable status field for add forms.
      $form['status']['#disabled'] = TRUE;
    }
    // Redirect user to the pam dashboard after each action, except preview.
    foreach (array_keys($form['actions']) as $action) {
      if ($action != 'preview' && isset($form['actions'][$action]['#type'])) {
        if ($form['actions'][$action]['#type'] === 'submit') {
          $form['actions'][$action]['#submit'][] = [
            __CLASS__,
            'contentRedirectSubmit',
          ];
        }
        if (isset($form['actions'][$action]['#value'])) {
          /** @var \Drupal\Core\StringTranslation\TranslatableMarkup $action_value */
          $action_value = $form['actions'][$action]['#value'];
        }
        elseif (isset($form['actions'][$action]['#title'])) {
          $action_value = $form['actions'][$action]['#title'];
        }
        else {
          $action_value = $this->t('modify');
        }
        $form['actions'][$action]['#attributes']['data-confirm'] = $this->t('Are you sure you want to @action the content?', [
          '@action' => $action_value->render(),
        ]);
      }
    }
    // Create hidden submit button for triggering the form submit
    // on confirmation popup.
    $form['actions']['buttons']['Submit'] = [
      '#type'   => 'submit',
      '#value'  => 'Save',
      '#submit' => $form['actions']['submit']['#submit'],
      '#id' => 'submit-after-confirmation',
      '#class' => 'button js-form-submit form-submit',
      '#attributes' => [
        'style' => ['display:none'],
      ],
    ];
    // Disable html5 validation as it interfere with the confirmation popup.
    $form['#attributes']['novalidate'] = 'novalidate';
    $form['#prefix'] = '<div id="dialog-confirm"></div>';
    // Attach library for confirmation popup.
    $form['#attached']['library'][] = 'ejp_content/confirm-popup';
  }

  /**
   * Form submission handler for save button.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public static function contentRedirectSubmit(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('ejp_pam.admin_pam');
  }

}
