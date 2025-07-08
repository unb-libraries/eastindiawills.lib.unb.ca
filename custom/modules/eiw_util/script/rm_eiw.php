<?php

$arg1 = isset($extra[0]) ? $extra[0] : NULL; // Delete data updated after this date.
$arg2 = $extra[1] === 'y' ? $extra[1] : NULL; // If set to 'y', delete taxonomy terms. 
$timestamp = $arg1 ? strtotime($arg1) : NULL;

if ($timestamp) {
  rm_entities('eiw_will', $timestamp);

  if ($arg2 === 'y') {
    rm_terms($timestamp);
  }
}

// Removes all nodes of type given 
function rm_entities($type, $timestamp) {
  $readable = date(DATE_ATOM, $timestamp);
  $handler = \Drupal::entityTypeManager()->getStorage('node');
  
  $entities = $handler->loadMultiple(\Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('changed', $timestamp, '>')
  ->condition('type', $type)
  ->execute());
  
  $handler->delete($entities);
  echo "All content of type [$type] changed after [$readable] removed.\n";
}

// Removes all taxonomy terms.
function rm_terms($timestamp) {
  $readable = date(DATE_ATOM, $timestamp);
  $handler = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
  $entities = $handler->loadMultiple(\Drupal::entityQuery('taxonomy_term')
    ->accessCheck(FALSE)
    ->condition('changed', $timestamp, '>')
    ->execute());

  $handler->delete($entities);
  echo "All taxonomy terms changed after [$readable] removed.\n";
}
