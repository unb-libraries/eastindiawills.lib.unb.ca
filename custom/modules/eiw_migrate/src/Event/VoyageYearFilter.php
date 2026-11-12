<?php

namespace Drupal\eiw_migrate\Event;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Limits the voyage migrations to the years in eiw_migrate.settings.
 *
 * The voyage CSVs cover the whole research period; only voyages departing in
 * voyage_years (inclusive), their stops, and the ports those stops use are
 * imported. Rows are skipped as they are read, without touching the ID map,
 * so "drush mim --sync" also rolls back anything imported from them earlier.
 *
 * Rows matched to content that existed before the import (ID map marked
 * "preserve", e.g. a port term already on the site) are never skipped:
 * --sync deletes regardless of that mark, and the import must not delete
 * what it didn't create.
 */
class VoyageYearFilter implements EventSubscriberInterface {

  /**
   * Port IDs used by in-range stops, built once per request.
   */
  protected ?array $ports = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // MigrateEvents::PREPARE_ROW (migrate_plus), by value: see ItemMigrateEvent.
    return ['migrate_plus.prepare_row' => 'onPrepareRow'];
  }

  /**
   * Skips voyages, stops and ports outside the configured years.
   */
  public function onPrepareRow(MigratePrepareRowEvent $event): void {
    $row = $event->getRow();
    $map = $event->getMigration()->getIdMap()->getRowBySource($row->getSourceIdValues());
    if (($map['rollback_action'] ?? NULL) == MigrateIdMapInterface::ROLLBACK_PRESERVE) {
      return;
    }
    switch ($event->getMigration()->id()) {
      case 'eiw_4_voyages':
        $this->skipUnlessInRange($row->getSourceProperty('year'));
        break;

      case 'eiw_3_voyage_stops':
        $this->skipUnlessInRange($row->getSourceProperty('voyage_year'));
        break;

      case 'eiw_2_ports':
        if (!isset($this->usedPorts()[$row->getSourceProperty('port_id')])) {
          [$from, $to] = $this->range();
          // FALSE: leave the ID map alone, so --sync can roll back earlier imports.
          throw new MigrateSkipRowException("port not used by any voyage departing $from–$to", FALSE);
        }
        break;
    }
  }

  /**
   * Throws a skip for a year outside the range.
   */
  protected function skipUnlessInRange(mixed $year): void {
    [$from, $to] = $this->range();
    if (!is_numeric($year) || $year < $from || $year > $to) {
      throw new MigrateSkipRowException("voyage year $year outside $from–$to", FALSE);
    }
  }

  /**
   * The configured [from, to] years.
   */
  protected function range(): array {
    $years = $this->configFactory->get('eiw_migrate.settings')->get('voyage_years') ?? [];
    return [(int) ($years['from'] ?? 0), (int) ($years['to'] ?? PHP_INT_MAX)];
  }

  /**
   * Port IDs used by stops of in-range voyages, from the stops CSV.
   */
  protected function usedPorts(): array {
    if ($this->ports === NULL) {
      $this->ports = [];
      [$from, $to] = $this->range();
      $path = $this->configFactory->get('migrate_plus.migration.eiw_3_voyage_stops')->get('source.path');
      if ($path && ($handle = fopen($path, 'r'))) {
        $header = fgetcsv($handle, escape: '');
        while (($values = fgetcsv($handle, escape: '')) !== FALSE) {
          $stop = array_combine($header, $values);
          if (is_numeric($stop['voyage_year']) && $stop['voyage_year'] >= $from && $stop['voyage_year'] <= $to) {
            $this->ports[$stop['port_id']] = TRUE;
          }
        }
        fclose($handle);
      }
    }
    return $this->ports;
  }

}
