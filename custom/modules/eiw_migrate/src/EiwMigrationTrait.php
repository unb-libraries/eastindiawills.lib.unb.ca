<?php

namespace Drupal\eiw_migrate;

/**
 * Trait for defining global properties for EIW migrations.
 */
trait EiwMigrationTrait {

  /**
   * Get the current migrations and sets.
   *
   * @return array
   *   An associative array containing applicable migration IDs/sets.
   */
  public function getMigrations() {
    return [
      'eiw_0_wills' => 'East India Wills from Tropy',
    ];
  }

}
