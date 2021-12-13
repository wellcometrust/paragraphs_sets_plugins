<?php

namespace Drupal\paragraphs_sets_plugins\Plugin\paragraphs_sets\process;

use Drupal\paragraphs_sets_plugins\PluginTransformProcessor;
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
 *      plugin: nested_entities
 *      data:
 *        -
 *          bundle: PARAGRAPH_TYPE
 *          field_SOME_FIELD: SIMPLE_VALUE
 *          field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *        -
 *          bundle: PARAGRAPH_TYPE
 *          field_SOME_FIELD: SIMPLE_VALUE
 *          field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *    ...
 *
 * Single delta fields are also supported:
 *
 *  data:
 *    ...
 *    field_SINGLE_PARAGRAPH_FIELD:
 *      plugin: nested_entities
 *      data:
 *        type: PARAGRAPH_TYPE
 *        field_SOME_FIELD: SIMPLE_VALUE
 *        field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *    ...
 *
 * Deeper recursive nesting is supported.
 *
 * @ParagraphsSetsProcess(
 *  id = "nested_entities",
 *  label = @Translation("Create a nested paragraph"),
 * )
 */
class NestedEntities extends ProcessPluginBase implements ProcessPluginInterface, ContainerFactoryPluginInterface {

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
   * The processor to transform field datas.
   *
   * @var PluginTransformProcessor
   */
  protected $pluginTransformProcessor;

  /**
   * Inject dependencies.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_manager, EntityFieldManagerInterface $field_manager, PluginTransformProcessor $plugin_transform_processor) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityManager = $entity_manager;
    $this->fieldManager = $field_manager;
    $this->pluginTransformProcessor = $plugin_transform_processor;
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
      $container->get('entity_field.manager'),
      $container->get('paragraphs_sets_plugins.plugin_transform_processor')
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
    if (!$entity_type) {
      return $source;
    }
    // Get bundle info.
    $allowed_bundles = $field_config->getSetting('handler_settings')['target_bundles'];
    if (empty($allowed_bundles)) {
      $allowed_bundles = array_keys($field_config->getSetting('handler_settings')['target_bundles_drag_drop']);
    }
    // Retrieve the target entity definition and storage.
    $entity_def = $this->entityManager->getDefinition($entity_type);
    $bundle_key = $entity_def ? $entity_def->getKey('bundle') : 'bundle';
    $entity_storage = $this->entityManager->getStorage($entity_type);

    // The target ID(s) to return.
    $value = [];

    foreach ($data as $delta_data) {
      if (!isset($delta_data['bundle']) || !in_array($delta_data['bundle'], $allowed_bundles)) {
        continue;
      }
      // Set up the data for creating the new entity.
      // `bundle` might exist in data already but this makes sure the key is correct.
      $delta_data[$bundle_key] = $delta_data['bundle'];
      $entity = NULL;

      $entity_fields_def = $this->fieldManager->getFieldDefinitions($entity_type, $delta_data['bundle']);
      $revisionEnabled = isset($entity_fields_def['revision_id']);
      // Reuse existing?
      if (isset($source['reuse_template_if_unmodified']) && $source['reuse_template_if_unmodified']) {

        $query = $this->entityManager->getStorage($entity_type)->getQuery();
        foreach ($delta_data as $data_k => $data_v) {
          // If the field exists on the entity and the value is scalar,
          // add it as a search condition.
          if (array_key_exists($data_k, $entity_fields_def) && is_scalar($data_v)) {
            $query->condition($data_k, $data_v);
          }
        }
        $found_ids = $query->execute();

        if ($found_ids) {
          $found_id = array_shift($found_ids);
          $entity = $entity_storage->load($found_id);
        }
      }

      if (!$entity) {
        $this->pluginTransformProcessor->transformRecursively($delta_data, ['paragraphs_bundle' => $delta_data['bundle']]);
        $entity = $entity_storage->create($delta_data);
        $entity->save();
      }

      if ($revisionEnabled) {
        $value[] = [
          'target_id' => $entity->id(),
          'target_revision_id' => $entity->getRevisionId(),
        ];
      } else {
        $value[] = $entity->id();
      }
    }

    // Convert back to scalar as needed.
    if ($is_scalar_data) {
      $value = array_shift($value);
    }
    return $value;
  }

}
