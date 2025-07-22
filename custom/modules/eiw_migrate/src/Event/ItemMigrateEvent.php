<?php

namespace Drupal\eiw_migrate\Event;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\eiw_migrate\EiwMigrationTrait;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate_plus\Event\MigrateEvents as MigratePlusEvents;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines the migrate event subscriber.
 */
class ItemMigrateEvent implements EventSubscriberInterface {
  
  use EiwMigrationTrait;
  
  /**
   * Dependency injection for entity_type.manager.
   *
   * @var Drupal\Core\Entity\EntityTypeManager
   */
  protected $typeManager;

  /**
   * Constructs a new InstanceMigrateEvent object.
   *
   * @param Drupal\Core\Entity\EntityTypeManager $type_manager
   *   Dependency injection for entity_type.manager.
   */
  public function __construct(EntityTypeManager $type_manager) {
    $this->typeManager = $type_manager;
  }

  /**
   * {@inheritdoc}\Drupal\taxonomy\Entity\Term
   */
  public static function getSubscribedEvents() {
    $events[MigratePlusEvents::PREPARE_ROW][] = ['onPrepareRow', 0];
    $events[MigrateEvents::POST_IMPORT][] = ['onPostImport', 0];
    return $events;
  }

  /**
   * React to a new row.
   *
   * @param Drupal\migrate_plus\Event\MigratePrepareRowEvent $event
   *   The prepare-row event.
   */
  public function onPrepareRow(MigratePrepareRowEvent $event) {
    $row = $event->getRow();
    $migration = $event->getMigration();
    $migration_id = $migration->id();

    // Only act on rows for current migration.
    if (array_key_exists($migration_id, self::getMigrations())) {

      switch ($migration_id) {
        case 'eiw_0_tropy':
          // Handle migration for eiw_0_tropy
          $result = $this->process_tropy($row);
          // If process fails, skip row.
          if (!$result) {
            return FALSE;
          }
          break;
        case 'eiw_1_gsheet':
          // Handle migration for eiw_1_gsheet
          break;
        default:
          // Handle unknown migration_id
          break;
      }
    }
  }

  /**
   * React to post-import.
   *
   * @param Drupal\migrate\Event\MigrateImportEvent $event
   *   The post-import event.
   */
  public function onPostImport(MigrateImportEvent $event) {
  }

  /**
   * Process Tropy migration row.
   */
  public function process_tropy(&$row) {
    // Parse notes.
    $notes_raw = $row->getSourceProperty('note');
    $tokens = explode('---', $notes_raw);
    $notes = [];
    
    foreach ($tokens as $token) {
      // Raw label: Everything before colon, trimmed.
      $label = $this->text_trim(strstr($token, ':', TRUE), FALSE);
      // Raw value: The rest, trimmed. 
      $value = $this->text_trim(str_replace($label, '', $token), FALSE);
      // Each value pair will be inthe form: 'a_label' => 'The value'. 
      $notes[preg_replace('/\s+/', '_', strtolower($label))] = $value;
    }

    $testator = $notes['testator'] ?? NULL;
    // If no testator, fail.
    if (!$testator) {
      return FALSE;
    }
    
    // Parse testator.
    $tokens = array_reverse(explode(' ', $testator));
    $last = $tokens[0];
    unset($tokens[0]);
    $tokens = array_reverse($tokens);
    $first = implode(' ', $tokens);
    // Update names.
    $row->setSourceProperty('first_name', $first);
    $row->setSourceProperty('last_name', $last);
    // Update reference.
    $reference = $notes['reference'] ?? NULL;
    $row->setSourceProperty('reference', $reference);
    // Update date of will.
    $date = $notes['date_of_will'] ?? NULL;
    $row->setSourceProperty('date_of_will', $date);
    // Update date of probate.
    $probate = $notes['date_of_probate'] ?? NULL;
    $row->setSourceProperty('date_of_probate', $probate);
  }

  /**
   * Get the ID of a node by field value and bundle.
   * If the node does not exist, create it with the specified field populated.
   *
   * @param string $field_name The machine name of the field (e.g., 'field_title').
   * @param mixed $field_value The value to search for and populate in the field.
   * @param string $bundle The content type machine name.
   * @return int|null The node ID (nid) or null on failure.
   */
  public function getOrCreateNodeId(string $field_name, $field_value, string $bundle): ?int {
    // Try to find the node by field value and bundle
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        $field_name => $field_value,
        'type' => $bundle,
      ]);

    if (!empty($nodes)) {
      // Node exists - return its ID
      $node = reset($nodes);
      return $node->id();
    }

    // Node does not exist - create it with only the specified field populated
    $new_node = Node::create([
      'type' => $bundle,
      $field_name => $field_value,
    ]);
    $new_node->save();

    return $new_node->id();
  }

  /**
   * Get the ID of a taxonomy term by name and vocabulary.
   * If the term does not exist, create it and return its ID.
   *
   * @param string $name The name of the taxonomy term.
   * @param string $vocabulary The vocabulary machine name.
   * @return int|null The term ID (tid) or null on failure.
   */
  public function getOrCreateTermId(string $name, string $vocabulary): ?int {
    // Try to find the term by name and vocabulary
    $term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => $name,
        'vid' => $vocabulary,
      ]);

    if (!empty($term)) {
      // Term exists - return its ID
      $term = reset($term);
      return $term->id();
    }

    // Term does not exist - create it
    $new_term = Term::create([
      'name' => $name,
      'vid' => $vocabulary,
    ]);
    $new_term->save();

    return $new_term->id();
  }

  /**
   * Trim spaces and special characters from text.
   *
   * @param string $text
   *   The text to process.
   * @param bool $sentence
   *   Is the text to be treated as a sentence?
   * @param string $starters
   *   Starter special characters to ignore for sentences.
   * @param string $enders
   *   Ender special characters to ignore for sentences.
   */
  public function text_trim(
    string $text, 
    bool $sentence = FALSE, 
    array $starters = ["'", '"', '(', '['], 
    array $enders = ['.', '!', '?' , "'", '"', ')', ']']) {
    $first = substr($text, 0, 1);
    $last = substr($text, -1);
    $starters = !$sentence ? [] : $starters;
    $enders = !$sentence ? [] : $enders;
  
    while ($first and !ctype_alnum($first) and !in_array($first, $starters)) {
      $text = substr($text, 1);
      $first = substr($text, 0, 1);
    }
    
    while ($first and !ctype_alnum($last) and !in_array($last, $enders)) {
      $text = substr($text, 0, -1);
      $last = substr($text, -1);
    }
  
  return $text;
  }

  /**
   * Dump item to terminal
   *
   * @param string $label
   *   A label for identification.
   * @param mixed $item
   *   The item to dump.
   */
  public function tdump($label, $item) {
    $label = strtoupper($label);
    echo "\n";
    echo "***$label***\n";
    echo var_dump($item);
    echo "***$label***";
    echo "\n\n";
  }

}