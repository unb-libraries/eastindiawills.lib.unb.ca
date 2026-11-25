<?php

/**
 * @file
 * Re-saves every will so eiw_core_node_presave() reapplies its title.
 *
 * Also regenerates each will's pathauto alias and PDF filename.
 *
 * Usage: drush scr resave_wills.php
 */

$storage = \Drupal::entityTypeManager()->getStorage('node');
$ids = array_values($storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'eiw_will')
  ->sort('nid')
  ->execute());

$total = count($ids);
$done = 0;
foreach (array_chunk($ids, 50) as $chunk) {
  foreach ($storage->loadMultiple($chunk) as $node) {
    $node->save();
  }
  $storage->resetCache($chunk);
  $done += count($chunk);
  echo "Re-saved $done / $total\n";
}
