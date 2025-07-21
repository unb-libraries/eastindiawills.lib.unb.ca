<?php

namespace Drupal\eiw_migrate\Event;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\eiw_migrate\EiwMigrationTrait;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Drupal\migrate_plus\Event\MigrateEvents as MigratePlusEvents;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;
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
   * {@inheritdoc}
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
          $this->process_tropy($row);
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
  public function process_tropy($row) {
    // Parse notes.
    $notes = $row->getSourceProperty('note');
    $tokens = explode('---', $notes);
    $values = [];
    
    foreach ($tokens as $token) {
      // Raw label: Everything before colon, trimmed.
      $label = $this->text_trim(strstr($token, ':', TRUE), FALSE);
      // Raw value: The rest, trimmed. 
      $value = $this->text_trim(str_replace($label, '', $token), FALSE);
      $values[preg_replace('/\s+/', '_', strtolower($label))] = $value;
    }
    dump($values);
    // Process title.
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