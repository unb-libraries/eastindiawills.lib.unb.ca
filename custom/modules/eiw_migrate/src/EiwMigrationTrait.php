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
      'eiw_0_tropy' => 'East India Wills from Tropy',
      'eiw_1_gs' => 'East India Wills from Google Sheets',
    ];
  }

}
