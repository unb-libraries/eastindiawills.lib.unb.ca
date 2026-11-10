<?php

namespace Drupal\eiw_migrate\Event;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Deletes voyage stops that a re-imported voyage no longer uses.
 *
 * Voyage stops are paragraphs held by a plain entity reference, so replacing
 * a voyage's stops (eiw_4_voyages) would leave the old ones orphaned. That
 * happens when the import is re-run after eiw_migrate was uninstalled (the ID
 * map is gone, so eiw_3_voyage_stops creates fresh stops), or when a voyage's
 * itinerary shrinks. Before each voyage is saved, its current stops are noted;
 * after, those it no longer references are deleted, unless some other node
 * still references them.
 */
class VoyageStopCleanup implements EventSubscriberInterface {

  /**
   * Stop IDs per voyage node ID, noted before the voyage is saved.
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
    if ($event->getMigration()->id() !== 'eiw_4_voyages') {
      return;
    }
    $nid = $event->getRow()->getDestinationProperty('nid');
    $voyage = $nid ? $this->entityTypeManager->getStorage('node')->load($nid) : NULL;
    if ($voyage && $voyage->hasField('field_voyage_stops')) {
      $this->previous[$nid] = array_column($voyage->get('field_voyage_stops')->getValue(), 'target_id');
    }
  }

  /**
   * Deletes the stops the saved voyage no longer references.
   */
  public function onPostRowSave(MigratePostRowSaveEvent $event): void {
    if ($event->getMigration()->id() !== 'eiw_4_voyages') {
      return;
    }
    $nid = $event->getDestinationIdValues()[0] ?? NULL;
    if (!$nid || empty($this->previous[$nid])) {
      return;
    }
    $voyage = $this->entityTypeManager->getStorage('node')->load($nid);
    $current = array_column($voyage->get('field_voyage_stops')->getValue(), 'target_id');
    $dropped = array_diff($this->previous[$nid], $current);
    unset($this->previous[$nid]);
    if (!$dropped) {
      return;
    }
    // Keep any stop that another node still references.
    $still_used = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_voyage_stops', $dropped, 'IN')
      ->execute();
    if ($still_used) {
      foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($still_used) as $other) {
        $dropped = array_diff($dropped, array_column($other->get('field_voyage_stops')->getValue(), 'target_id'));
      }
    }
    $storage = $this->entityTypeManager->getStorage('paragraph');
    $stops = array_filter($storage->loadMultiple($dropped), fn($p) => $p->bundle() === 'eiw_voyage_stop');
    $storage->delete($stops);
  }

}
