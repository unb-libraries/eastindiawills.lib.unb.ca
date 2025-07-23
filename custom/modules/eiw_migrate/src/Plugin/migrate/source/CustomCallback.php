<?php

namespace Drupal\eiw_migrate\Plugin\migrate\source;

use Drupal\migrate\Plugin\migrate\source\Callback;
use Drupal\migrate\Row;

/**
 * Custom Callback source plugin to allow row skipping.
 *
 * @MigrateSource(
 *   id = "custom_callback"
 * )
 */
class CustomCallback extends Callback {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // Skip row if last_name is missing or empty.
    echo($row->getSourceProperty('last_name'));
    if (!$row->getSourceProperty('last_name')) {
      return FALSE;
    }
    // Otherwise, process as normal.
    return parent::prepareRow($row);
  }

}