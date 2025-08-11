<?php

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;

// Usage: drush php:script rm_content.php [YYYY-MM-DD]

// If no date is passed, use today's date (current time).
$date_arg = isset($extra[0]) ? $extra[0] : null;


if (!empty($date_arg)) {
  $date_string = $date_arg;
  $timestamp = strtotime($date_string);
  if (!$timestamp) {
    echo "Invalid date format. Use YYYY-MM-DD.\n";
    exit(1);
  }
} else {
  $timestamp = time() - 21600;
  $date_string = date('Y-m-d', $timestamp);
  echo "No date parameter passed. Defaulting to current date: $date_string\n";
}

// Delete nodes created after the timestamp.
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$nids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('created', $timestamp, '>=')
  ->execute();

if (!empty($nids)) {
  $nodes = $node_storage->loadMultiple($nids);
  foreach ($nodes as $node) {
    $node->delete();
    echo "Deleted node {$node->id()} ({$node->getTitle()})\n";
  }
} else {
  echo "No nodes found since $date_string.\n";
}

// Delete taxonomy terms **changed** after the timestamp.
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$tids = \Drupal::entityQuery('taxonomy_term')
  ->accessCheck(FALSE)
  ->condition('changed', $timestamp, '>=')
  ->execute();

if (!empty($tids)) {
  $terms = $term_storage->loadMultiple($tids);
  foreach ($terms as $term) {
    $term->delete();
    echo "Deleted term {$term->id()} ({$term->getName()})\n";
  }
} else {
  echo "No taxonomy terms found modified since $date_string.\n";
}

// Delete all managed files (file entities).
$file_ids = \Drupal::entityQuery('file')
  ->condition('created', $timestamp, '>')
  ->accessCheck(FALSE)
  ->execute();

if (empty($file_ids)) {
  echo "No managed files found created after timestamp {$timestamp}.\n";
  exit;
}

$storage = \Drupal::entityTypeManager()->getStorage('file');
$files = $storage->loadMultiple($file_ids);

foreach ($files as $file) {
  /** @var \Drupal\file\FileInterface $file */
  $uri = $file->getFileUri();
  $created = $file->getCreatedTime();
  echo "Deleting file entity: {$file->id()} ({$uri}) - Created: {$created}\n";
  try {
    // Delete the file entity (removes references in Drupal).
    $file->delete();

    // Remove the physical file if it exists.
    if (file_exists($file->getFileUri())) {
      $result = \Drupal::service('file_system')->delete($uri, FileSystemInterface::DELETE);
      if ($result) {
        echo "Deleted physical file: {$uri}\n";
      } else {
        echo "Failed to delete physical file: {$uri}\n";
      }
    } else {
      echo "Physical file does not exist: {$uri}\n";
    }
  } catch (Exception $e) {
    echo "Error deleting file {$file->id()}: " . $e->getMessage() . "\n";
  }
}

echo "Done.\n";
