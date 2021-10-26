<?php

namespace Drupal\paragraphs_sets_plugins\Plugin\paragraphs_sets\process;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * For sets config entries adding content to reference fields.
 *
 * Example:
 *
 *  data:
 *    ...
 *    field_ENTITY_REFERENCE_FIELD:
 *      plugin: create_entity
 *      reuse_template_if_unmodified: true
 *      data:
 *        bundle: BUNDLE_TYPE
 *        title: ENTITY_TITLE
 *        field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *    field_ENTITY_REFERENCE_MULTIDELTA_FIELD:
 *      plugin: create_entity
 *      data:
 *        -
 *          bundle: ENTITY_BUNDLE
 *          title: FIRST_ENTITY_TITLE
 *          field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *        -
 *          bundle: ENTITY_BUNDLE
 *          title: SECOND_ENTITY_TITLE
 *          field_SOME_OTHER_FIELD: SIMPLE_VALUE
 *    ...
 *
 * Recursive entity-in-entity creation is not supported.
 *
 * The `reuse_template_if_unmodified` configuration key is an attempt to
 * reduce orphaned entities. If set to `true` and an entity
 * already exists that exactly matches the templates defined
 * in the set configuration, it will be referenced (rather
 * than the plugin creating a new entity).
 *
 * @ParagraphsSetsProcess(
 *  id = "create_entity",
 *  label = @Translation("Create a new referenced entity"),
 * )
 */
class CreateEntity extends ProcessPluginBase implements ProcessPluginInterface, ContainerFactoryPluginInterface {

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
    if (!$entity_type) {
      return $source;
    }
    // Get bundle info.
    $allowed_bundles = $field_config->getSetting('handler_settings')['target_bundles'];
    // Retrieve the target entity definition and storage.
    $entity_def = $this->entityManager->getDefinition($entity_type);
    $bundle_key = $entity_def->getKey('bundle');
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

      // Reuse existing?
      if (isset($source['reuse_template_if_unmodified']) && $source['reuse_template_if_unmodified']) {
        $query = $this->entityTypeManager->getStorage($entity_type)->getQuery();
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
        $entity = $entity_storage->create($delta_data);
        $entity->save();
      }

      $value[] = $entity->id();
    }

    // Convert back to scalar as needed.
    if ($is_scalar_data) {
      $value = array_shift($value);
    }
    return $value;
  }

}
