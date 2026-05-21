<?php

namespace Drupal\eiw_core\Plugin\search_api\processor;

use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\taxonomy\Entity\Term;

/**
 * Adds the role names from referenced voyages to the indexed data.
 *
 * @SearchApiProcessor(
 *   id = "eiw_core_index_will_role_names",
 *   label = @Translation("Will Role Names"),
 *   description = @Translation("Indexes all role names referenced by a will through will voyages."),
 *   stages = {
 *     "add_properties" = 0,
 *     "alter_items" = 0,
 *   }
 * )
 */
class IndexWillRoleNames extends ProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL) {
    $properties = [];
    $type = $datasource ? $datasource->getEntityTypeId() : 'No datasource';
    
    if (!$datasource || $datasource->getEntityTypeId() === 'node') {
      $definition = [
        'label' => $this->t('Will Role Names'),
        'description' => $this->t('All role names referenced by this will via voyages.'),
        'type' => 'string',
        'is_list' => TRUE,
        'processor_id' => $this->getPluginId(),
      ];
      $properties['eiw_will_role_names'] = new ProcessorProperty($definition);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item) {
    $node = $item->getOriginalObject()->getValue();

    // Only apply to will nodes.
    if ($node instanceof NodeInterface && $node->bundle() == 'eiw_will') {
      $role_names = [];

      // Get paragraphs referenced in field_will_voyages.
      if ($node->hasField('field_will_voyages')) {
        foreach ($node->get('field_will_voyages') as $paragraph_ref) {
          /** @var \Drupal\paragraphs\Entity\Paragraph $paragraph */
          $paragraph = $paragraph_ref->entity;
          if ($paragraph instanceof Paragraph && $paragraph->bundle() == 'eiw_will_voyage') {
            // Get the field_role term reference.
            if ($paragraph->hasField('field_role') && !$paragraph->get('field_role')->isEmpty()) {
              $term = $paragraph->get('field_role')->entity;
              if ($term instanceof Term) {
                $role_names[] = $term->getName();
              }
            }
          }
        }
      }
      // Remove duplicates and add to the index.
      $role_names = array_unique($role_names);

      if (!empty($role_names)) {
        $fields = $this->getFieldsHelper()
          ->filterForPropertyPath($item->getFields(), NULL, 'eiw_will_role_names');
        
        foreach ($fields as $field) {
          foreach ($role_names as $name) {
            $field->addValue($name);
          }
        }
      }
    }
  }

}