<?php

namespace Drupal\eiw_core\Plugin\search_api\processor;

use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds the voyage names from referenced voyages to the indexed data.
 *
 * @SearchApiProcessor(
 *   id = "eiw_core_index_will_voyage_names",
 *   label = @Translation("Will Voyage Names"),
 *   description = @Translation("Indexes all voyage names referenced by a will through will voyages."),
 *   stages = {
 *     "add_properties" = 0,
 *     "alter_items" = 0,
 *   }
 * )
 */
class IndexWillVoyageNames extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];
    // Allow for either NULL or node datasource (robust).
    if (!$datasource || $datasource->getEntityTypeId() === 'node') {
      $definition = [
        'label' => $this->t('Will Voyage Names'),
        'description' => $this->t('All voyage names referenced by this will via voyages.'),
        'type' => 'string',
        'is_list' => TRUE,
        'processor_id' => $this->getPluginId(),
      ];
      $properties['eiw_will_voyage_names'] = new ProcessorProperty($definition);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $node = $item->getOriginalObject()->getValue();

    if ($node instanceof NodeInterface && $node->bundle() == 'eiw_will') {
      $voyage_names = [];

      if ($node->hasField('field_will_voyages')) {
        foreach ($node->get('field_will_voyages') as $paragraph_ref) {
          $paragraph = $paragraph_ref->entity;
          if ($paragraph instanceof Paragraph && $paragraph->bundle() == 'eiw_will_voyage') {
            if ($paragraph->hasField('field_voyage') && !$paragraph->get('field_voyage')->isEmpty()) {
              $voyage = $paragraph->get('field_voyage')->entity;
              if ($voyage instanceof NodeInterface) {
                $voyage_names[] = $voyage->label();
              }
            }
          }
        }
      }

      $voyage_names = array_unique($voyage_names);

      if (!empty($voyage_names)) {
        $fields = $this->getFieldsHelper()
          ->filterForPropertyPath($item->getFields(), NULL, 'eiw_will_voyage_names');
        foreach ($fields as $field) {
          foreach ($voyage_names as $name) {
            $field->addValue($name);
          }
        }
      }
    }
  }

}