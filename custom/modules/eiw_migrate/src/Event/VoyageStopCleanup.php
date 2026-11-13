<?php

namespace Drupal\eiw_migrate\Event;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Deletes voyage stops (and identified ships) that a re-imported node no
 * longer uses.
 *
 * Voyage stops are paragraphs held by a plain entity reference, so replacing
 * a voyage's stops (eiw_4_voyages) would leave the old ones orphaned. The
 * same goes for the identified ships on a ship node (eiw_7_ships); see
 * FIELDS. That
 * happens when the import is re-run after eiw_migrate was uninstalled (the ID
 * map is gone, so eiw_3_voyage_stops creates fresh stops), or when a voyage's
 * itinerary shrinks. Before each voyage is saved, its current stops are noted;
 * after, those it no longer references are deleted, unless some other node
 * still references them.
 */
class VoyageStopCleanup implements EventSubscriberInterface {

  /**
   * Paragraph reference field and paragraph bundle, per node migration.
   */
  const FIELDS = [
    'eiw_4_voyages' => ['field_voyage_stops', 'eiw_voyage_stop'],
    'eiw_7_ships' => ['field_ship_identities', 'eiw_ship_identity'],
  ];

  /**
   * Paragraph IDs per node ID, noted before the node is saved.
   */
  protected array $previous = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      MigrateEvents::PRE_ROW_SAVE => 'onPreRowSave',
      MigrateEvents::POST_ROW_SAVE => 'onPostRowSave',
    ];
  }

  /**
   * Notes the stops of a voyage about to be updated.
   */
  public function onPreRowSave(MigratePreRowSaveEvent $event): void {
    [$field] = static::FIELDS[$event->getMigration()->id()] ?? [NULL];
    if (!$field) {
      return;
    }
    $nid = $event->getRow()->getDestinationProperty('nid');
    $node = $nid ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    if ($node && $node->hasField($field)) {
      $this->previous[$nid] = array_column($node->get($field)->getValue(), 'target_id');
    }
  }

  /**
   * Deletes the stops the saved voyage no longer references.
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event): void {
    [$field, $bundle] = static::FIELDS[$event->getMigration()->id()] ?? [NULL, NULL];
    if (!$field) {
      return;
    }
    $nid = $event->getDestinationIdValues()[0] ?? NULL;
    if (!$nid || empty($this->previous[$nid])) {
      return;
    }
    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    $current = array_column($node->get($field)->getValue(), 'target_id');
    $dropped = array_diff($this->previous[$nid], $current);
    unset($this->previous[$nid]);
    if (!$dropped) {
      return;
    }
    // Keep any paragraph that another node still references.
    $still_used = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $dropped, 'IN')
      ->execute();
    if ($still_used) {
      foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($still_used) as $other) {
        $dropped = array_diff($dropped, array_column($other->get($field)->getValue(), 'target_id'));
      }
    }
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $paragraphs = array_filter($storage->loadMultiple($dropped), fn($p) => $p->bundle() === $bundle);
    $storage->delete($paragraphs);
  }

}
