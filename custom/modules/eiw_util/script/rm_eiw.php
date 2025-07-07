<?php

$arg1 = isset($extra[0]) ? $extra[0] : NULL;
$timestamp = $arg1 ? strtotime($arg1) : time() - 7200;

if ($timestamp) {
  rm_entities('eiw_will', $timestamp);
  rm_terms($timestamp);
}

function rm_entities($type, $timestamp) {
  $readable = date(DATE_ATOM, $timestamp);
  $handler = \Drupal::entityTypeManager()->getStorage('node');
  
  $entities = $handler->loadMultiple(\Drupal::entityQuery('node')
    ->accessCheck(FALSE)
    ->condition($changed, $timestamp, '>')
    ->condition('bundle', $type)
    ->execute());

  $handler->delete($entities);
  echo "All content of type [$type] $changed after [$readable] removed.\n";
}

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
