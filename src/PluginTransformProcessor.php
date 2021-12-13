<?php

namespace Drupal\paragraphs_sets_plugins;

use Drupal\Component\Plugin\PluginManagerInterface;

/**
 * Provides a processor to transform fields value in paragraphs.
 */
class PluginTransformProcessor {

  /**
   * The plugin manager.
   * 
   * @var Drupal\Component\Plugin\PluginManagerInterface
   */
  private $pluginManager;

  /**
   * Constructs a OutputManager object.
   *
   * @param Drupal\Component\Plugin\PluginManagerInterface $plugin_manager
   *   Plugin Manager.
   */
  public function __construct(PluginManagerInterface $plugin_manager) {
    $this->pluginManager = $plugin_manager;
  }

  /**
   * Process nested entities.
   */
  public function transformRecursively(&$data, $context) {
    foreach ($data as $field_key => $source_info) {
      // Skip plugin transformation if not configured.
      if (!is_array($source_info) || !isset($source_info['plugin'])) {
        continue;
      }
      // Run the transform if the plugin is available.
      if ($plugin = $this->pluginManager->createInstance($source_info['plugin'])) {
        $data[$field_key] = $plugin->transform($source_info, $field_key, $context);
      }
    }
  }

}
