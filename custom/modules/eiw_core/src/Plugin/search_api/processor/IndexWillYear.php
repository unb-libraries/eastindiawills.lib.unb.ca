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
 *   id = "index_article_year",
 *   label = @Translation("Will Year Index"),
 *   description = @Translation("Adds sepparate integer year field to indexed legal articles."),
 *   stages = {
 *     "add_properties" = 0,
 *   },
 *   locked = true,
 *   hidden = true,
 * )
 */
class IndexArticleYear extends ProcessorPluginBase {

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
        'label' => $this->t('Will Probate Year'),
        'description' => $this->t('Year the will was put in effect.'),
        'type' => 'integer',
        'is_list' => FALSE,
        'processor_id' => $this->getPluginId(),
      ];
      $properties['field_date_of_probate'] = new ProcessorProperty($definition);
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
        // Years published.
        $fields = $this->getFieldsHelper()
          ->filterForPropertyPath($item->getFields(), NULL, 'field_date_of_probate');

        foreach ($fields as $field) {
          if (!empty($node->get('field_date')->date)) {
            $year = (int) $node->get('field_date')->date->format('Y');
            $field->addValue($year);
          }
        }
      }
    }
  }

}
