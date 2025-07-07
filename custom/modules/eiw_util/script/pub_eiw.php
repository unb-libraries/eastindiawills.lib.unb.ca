<?php

$arg1 = isset($extra[0]) ? $extra[0] : NULL;
$arg2 = isset($extra[1]) and ($extra[1] == 'y') ? TRUE : FALSE;
$timestamp = strtotime($arg1);

if ($timestamp) {
  pub_entities('eiw_will', $timestamp, $arg2);
}

function pub_entities($type, $timestamp, $publish) {
  $readable = date(DATE_ATOM, $timestamp);
  $handler = \Drupal::entityTypeManager()->getStorage($type);
  $entities = $handler->loadMultiple(\Drupal::entityQuery($type)
    ->accessCheck(FALSE)
    ->condition('created', $timestamp, '>')
    ->execute());

  $update = $publish ? 'published' : 'unpublished';

  foreach ($entities as $entity) {
    $entity->setPublished($publish)->save();
  }

  echo "All entities of type [$type] modified after [$readable] $update.\n";
}

