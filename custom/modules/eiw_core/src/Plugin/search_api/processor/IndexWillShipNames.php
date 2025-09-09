<?php

namespace Drupal\eiw_core\Plugin\search_api\processor;

use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Adds the ship names from referenced voyages to the indexed data.
 *
 * @SearchApiProcessor(
 *   id = "eiw_core_index_will_ship_names",
 *   label = @Translation("Will Ship Names"),
 *   description = @Translation("Indexes all ship names referenced by a will through will voyages."),
 *   stages = {
 *     "add_properties" = 0,
 *     "alter_items" = 0,
 *   }
 * )
 */
class IndexWillShipNames extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(DatasourceInterface $datasource = NULL) {
    $properties = [];
    // Allow for either NULL or node datasource (robust).
    if (!$datasource || $datasource->getEntityTypeId() === 'node') {
      $definition = [
        'label' => $this->t('Will Ship Names'),
        'description' => $this->t('All ship names referenced by this will via voyages.'),
        'type' => 'string',
        'is_list' => TRUE,
        'processor_id' => $this->getPluginId(),
      ];
      $properties['eiw_will_ship_names'] = new ProcessorProperty($definition);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $node = $item->getOriginalObject()->getValue();

    if ($node instanceof NodeInterface && $node->bundle() == 'eiw_will') {
      $ship_names = [];

      if ($node->hasField('field_will_voyages')) {
        foreach ($node->get('field_will_voyages') as $paragraph_ref) {
          $paragraph = $paragraph_ref->entity;
          if ($paragraph instanceof Paragraph && $paragraph->bundle() == 'eiw_will_voyage') {
            if ($paragraph->hasField('field_ship') && !$paragraph->get('field_ship')->isEmpty()) {
              $ship = $paragraph->get('field_ship')->entity;
              if ($ship instanceof NodeInterface) {
                $ship_names[] = $ship->label();
              }
            }
          }
        }
      }

      $ship_names = array_unique($ship_names);

      if (!empty($ship_names)) {
        $fields = $this->getFieldsHelper()
          ->filterForPropertyPath($item->getFields(), NULL, 'eiw_will_ship_names');
        foreach ($fields as $field) {
          foreach ($ship_names as $name) {
            $field->addValue($name);
          }
        }
      }
    }
  }

}