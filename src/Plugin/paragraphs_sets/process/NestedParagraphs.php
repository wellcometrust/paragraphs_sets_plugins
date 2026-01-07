<?php

namespace Drupal\paragraphs_sets_plugins\Plugin\paragraphs_sets\process;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * For sets config entries adding nested paragraphs to reference fields.
 *
 * Example:
 *
 *  data:
 *    ...
 *    field_PARAGRAPHS_FIELD:
 *      plugin: nested_paragraphs
 *      data:
 *        -
 *          type: PARAGRAPH_TYPE
 *          field_SOME_FIELD: SIMPLE_VALUE
 *          field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *        -
 *          type: PARAGRAPH_TYPE
 *          field_SOME_FIELD: SIMPLE_VALUE
 *          field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *    ...
 *
 * Single delta fields are also supported:
 *
 *  data:
 *    ...
 *    field_SINGLE_PARAGRAPH_FIELD:
 *      plugin: nested_paragraphs
 *      data:
 *        type: PARAGRAPH_TYPE
 *        field_SOME_FIELD: SIMPLE_VALUE
 *        field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *    ...
 *
 * Deeper recursive nesting is not currently supported.
 *
 * @ParagraphsSetsProcess(
 *  id = "nested_paragraphs",
 *  label = @Translation("Create a nested paragraph"),
 * )
 */
class NestedParagraphs extends ProcessPluginBase implements ProcessPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The entity manager.
   *
   * @var Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityManager;

  /**
   * The entity field manager.
   *
   * @var Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $fieldManager;

  /**
   * Inject dependencies.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, EntityFieldManagerInterface $field_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityManager = $entity_manager;
    $this->fieldManager = $field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($source, string $fieldname, array $context) {
    // Require some config.
    if (!isset($source['data'])) {
      return $source;
    }

    $data = $source['data'];
    // If not a multi-delta data array, make it one.
    $data_keys = array_keys($data);
    $numeric_keys = array_filter($data_keys, 'is_numeric');
    $is_scalar_data = (count($numeric_keys) == 0);
    if ($is_scalar_data) {
      $data = [$data];
    }

    // Figure out the target type and the bundle key for this entity.
    $fields_def = $this->fieldManager->getFieldDefinitions('paragraph', $context['paragraphs_bundle']);
    if (!isset($fields_def[$fieldname])) {
      return $source;
    }
    $field_config = $fields_def[$fieldname];
    $entity_type = $field_config->getSetting('target_type');
    if (!$entity_type || $entity_type != 'paragraph') {
      return $source;
    }
    // Get bundle info.
    $allowed_bundles = $field_config->getSetting('handler_settings')['target_bundles'];
    $entity_storage = $this->entityManager->getStorage($entity_type);

    // The target ID(s) to return.
    $value = [];

    foreach ($data as $delta_data) {
      // Requested type must be an allowed type.
      if (!isset($delta_data['type']) || !in_array($delta_data['type'], $allowed_bundles)) {
        continue;
      }

      $paragraph = NULL;
      $paragraph = $entity_storage->create($delta_data);
      $paragraph->save();

      $value[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
    }

    // Convert back to scalar as needed.
    if ($is_scalar_data) {
      $value = array_shift($value);
    }
    return $value;
  }

}
