<?php

namespace Drupal\eiw_core\Plugin\search_api\processor;

use Drupal\node\NodeInterface;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds sepparate integer year field to indexed legal articles.
 *
 * @SearchApiProcessor(
 *   id = "index_will_year",
 *   label = @Translation("Year of Will Index"),
 *   description = @Translation("Adds sepparate integer year of will field to indexed wills."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class IndexWillYear extends ProcessorPluginBase {

  /**
   * Only enabled for node indexes.
   *
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index) {
    foreach ($index->getDatasources() as $datasource) {
      if ($datasource->getEntityTypeId() == 'node') {
        return TRUE;
      }
    }
    
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];

    if (!$datasource) {
      $definition = [
        'label' => $this->t('Will Year'),
        'description' => $this->t('Will Year.'),
        'type' => 'integer',
        'is_list' => FALSE,
        'processor_id' => $this->getPluginId(),
      ];
      $properties['field_year_of_will'] = new ProcessorProperty($definition);
    }

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $node = $item->getOriginalObject()->getValue();

    if ($node instanceof NodeInterface) {
      // Only apply to will nodes.
      if ($node->bundle() == 'eiw_will') {
        // Year published.
        $fields = $this->getFieldsHelper()
          ->filterForPropertyPath($item->getFields(), NULL, 'field_year_of_will');

        foreach ($fields as $field) {
          if (!empty($node->get('field_date_of_will')->date)) {
            $year = (int) $node->get('field_date_of_will')->date->format('Y');
            $field->addValue($year);
          }
        }
      }
    }
  }

}
