<?php

namespace Drupal\confirm_submit;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ContentHandler handles custom logic related to content operations.
 *
 * @package Drupal\confirm_submit
 */
class ContentHandler implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * UUID generator interface.
   *
   * @var \Drupal\Component\Uuid\UuidInterface
   */
  protected UuidInterface $uuidGenerator;

  /**
   * ContentHandler constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid_generator
   *   UUID generator interface.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, LanguageManagerInterface $languageManager, UuidInterface $uuid_generator) {
    $this->entityTypeManager = $entityTypeManager;
    $this->languageManager = $languageManager;
    $this->uuidGenerator = $uuid_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('uuid'),
    );
  }

  /**
   * Implements logic on content insert.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node entity.
   */
  public function nodeInsert(NodeInterface $node) {
    // If the content have the titles per language field, we need to create the
    // content for each language, for each member state.
    // The base node will have European Commission as the member state.
    if ($node->hasField('field_titles_per_language') && $node->get('field_ejp_relationship_unique_id')->isEmpty()) {
      $titles_per_language = $node->get('field_titles_per_language')->first()->getValue();

      // Define a unique uuid for the content linking.
      $uuid = $this->uuidGenerator->generate();
      $europeanCommission = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
        'vid' => 'ejp_regions',
        'name' => 'European commission',
      ]);
      $europeanCommission = reset($europeanCommission);
      $node->set('moderation_state', 'draft');
      $node->set('field_ejp_relationship_unique_id', $uuid);
      $node->set('field_ejp_member_state', [
        'target_id' => $europeanCommission->id(),
        'target_type' => 'ejp_regions',
      ]);
      // The chapeau page in all the languages is created in every case.
      foreach ($titles_per_language['value'] as $language_id => $item) {
        if (!$node->hasTranslation($language_id)) {
          $node->addTranslation($language_id, [
            'title' => $item['title'],
            'moderation_state' => 'draft',
            'status' => $node->isPublished(),
            'field_ejp_member_state' => [
              'target_id' => $europeanCommission->id(),
              'target_type' => 'ejp_regions',
            ],
          ]);
        }
      }
      $node->save();

      // @todo This needs to be clarified.
      //   Load all the member states except the European commission, we may
      //   need to also load it and create a new node for it and the first
      //   created node to be set as "chapeau" page.
      $memberStates = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
        ->accessCheck(FALSE)
        ->condition('vid', 'ejp_regions')
        ->condition('name', 'European commission', '!=');
      $memberStates = $memberStates->execute();
      foreach ($memberStates as $memberStateId) {
        $memberStateNode = $node->createDuplicate();
        $memberStateNode->set('field_ejp_member_state', [
          'target_id' => $memberStateId,
          'target_type' => 'ejp_regions',
        ]);

        foreach ($titles_per_language['value'] as $langcode => $item) {
          $translation = $memberStateNode->getTranslation($langcode);
          $translation->set('field_ejp_member_state', [
            'target_id' => $memberStateId,
            'target_type' => 'ejp_regions',
          ]);
        }

        $memberStateNode->save();
      }
    }
  }

  /**
   * Implements logic on content presave for the pam.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node entity.
   */
  public function nodePamPresave(NodeInterface $node) {
    // As we are using the language title field, we need to save the title
    // field value programatically.
    if ($node->isNew()) {
      $titles_per_language = $node->get('field_titles_per_language')->first()->getValue();
      $node_language = $node->language()->getId();
      // We make sure that the title of the node is saved from the list of
      // titles per language.
      if ($node->get('title')->getString() != $titles_per_language['value'][$node_language]['title']) {
        $title = $titles_per_language['value'][$node_language]['title'];
        $node->setTitle($title);
      }
    }
  }

  /**
   * Implements logic on content update.
   *
   * @param \Drupal\node\NodeInterface $node
   *   Node entity.
   */
  public function nodeUpdate(NodeInterface $node) {
    // In PAM, we will always handle only the "parent" node.
    // We need extra checks to differentiate it.
    if ($node->isDefaultTranslation() && $node->hasField('field_titles_per_language') && $node->hasField('field_ejp_member_state')) {

      $member_state = $node->get('field_ejp_member_state')->referencedEntities();
      $titles_per_language = $node->get('field_titles_per_language')->first()->getValue();
      if (!empty($member_state)) {

        /** @var \Drupal\taxonomy\Entity\Term $member_state */
        $member_state = reset($member_state);
        if ($member_state->getName() == 'European commission') {
          $this->updateTitlePerLanguage($titles_per_language['value'], $node);

          $linked_nodes = $this->entityTypeManager->getStorage('node')->getQuery()
            ->accessCheck(FALSE)
            ->condition('field_ejp_relationship_unique_id', $node->get('field_ejp_relationship_unique_id')->getString(), '=')
            ->condition('nid', $node->id(), '!=')
            ->execute();

          /** @var \Drupal\node\Entity\Node $linked_node */
          foreach ($linked_nodes as $linked_node_id) {
            $linked_node = $this->entityTypeManager->getStorage('node')->load($linked_node_id);
            $this->updateTitlePerLanguage($titles_per_language['value'], $linked_node, TRUE);
          }
        }
      }
    }
  }

  /**
   * Updates the title of each node and translation according to the language.
   *
   * @param array $titles_per_language
   *   An array containing the value of the title for each language.
   * @param \Drupal\node\NodeInterface $node
   *   The node entity that needs to be updated.
   * @param bool $to_save
   *   TRUE if the node needs to be saved, FALSE otherwise.
   */
  private function updateTitlePerLanguage(array $titles_per_language, NodeInterface $node, bool $to_save = FALSE): void {
    foreach ($titles_per_language as $langcode => $value) {
      $title = $value['title'];
      if ($node->hasTranslation($langcode)) {
        $translation = $node->getTranslation($langcode);
        if ($translation->get('title')->getString() != $title) {
          $translation->setTitle($title);
        }
      }
      else {
        $node->addTranslation($langcode, [
          'title' => $title,
          'moderation_state' => 'draft',
          'status' => $node->isPublished(),
          'field_ejp_member_state' => [
            'target_id' => $node->get('field_ejp_member_state')->getString(),
            'target_type' => 'ejp_regions',
          ],
        ]);
      }

    }

    if ($to_save) {
      $node->save();
    }
  }

}
