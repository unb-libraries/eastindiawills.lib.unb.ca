<?php

namespace Drupal\eiw_migrate\Event;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\migrate\MigrateSkipRowException;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Row;
use Drupal\migrate_plus\Event\MigratePrepareRowEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Limits the voyage and ship migrations to the years in eiw_migrate.settings.
 *
 * The CSVs cover the whole research period; only voyages departing in
 * voyage_years (inclusive), their stops, the ports those stops use, the ships
 * in service in those years, and those ships' pictures are imported. Rows are skipped as they are read, without touching the ID map,
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

  /**
   * Image IDs used by in-range ships, built once per request.
   */
  protected ?array $images = NULL;

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
          throw new MigrateSkipRowException("port not used by any voyage departing {$from}–{$to}", FALSE);
        }
        break;

      case 'eiw_6_ship_identities':
        if (!$this->shipInRange($row->getSource())) {
          [$from, $to] = $this->range();
          throw new MigrateSkipRowException("ship not in service {$from}–{$to}", FALSE);
        }
        break;

      case 'eiw_5_ship_images':
        if (!isset($this->usedImages()[$row->getSourceProperty('image_id')])) {
          [$from, $to] = $this->range();
          throw new MigrateSkipRowException("image not used by any ship in service {$from}–{$to}", FALSE);
        }
        break;
    }
  }

  /**
   * Whether a ship (eiw_ship_identities.csv row) belongs in the range.
   *
   * In range if its service span overlaps the range, or any of its voyages
   * departs in it. A ship with no dates at all is kept: nothing says it
   * falls outside.
   */
  protected function shipInRange(array $ship): bool {
    [$from, $to] = $this->range();
    $years = array_filter(explode('|', $ship['voyage_years'] ?? ''), 'is_numeric');
    foreach ($years as $year) {
      if ($year >= $from && $year <= $to) {
        return TRUE;
      }
    }
    $start = is_numeric($ship['service_from'] ?? '') ? (int) $ship['service_from'] : NULL;
    $end = is_numeric($ship['service_to'] ?? '') ? (int) $ship['service_to'] : $start;
    $start ??= $end;
    if ($start === NULL) {
      return !$years;
    }
    return $start <= $to && $end >= $from;
  }

  /**
   * Throws a skip for a year outside the range.
   */
  protected function skipUnlessInRange(mixed $year): void {
    [$from, $to] = $this->range();
    if (!is_numeric($year) || $year < $from || $year > $to) {
      throw new MigrateSkipRowException("voyage year $year outside {$from}–{$to}", FALSE);
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
      foreach ($this->sourceRows('eiw_3_voyage_stops') as $stop) {
        if (is_numeric($stop['voyage_year']) && $stop['voyage_year'] >= $from && $stop['voyage_year'] <= $to) {
          $this->ports[$stop['port_id']] = TRUE;
        }
      }
    }
    return $this->ports;
  }

  /**
   * Image IDs used by in-range ships, from the ship identities CSV.
   */
  protected function usedImages(): array {
    if ($this->images === NULL) {
      $this->images = [];
      foreach ($this->sourceRows('eiw_6_ship_identities') as $ship) {
        if ($ship['image_id'] !== '' && $this->shipInRange($ship)) {
          $this->images[$ship['image_id']] = TRUE;
        }
      }
    }
    return $this->images;
  }

  /**
   * Reads the rows of a migration's CSV source, keyed by column name.
   */
  protected function sourceRows(string $migration): \Generator {
    $path = $this->configFactory->get("migrate_plus.migration.$migration")->get('source.path');
    if ($path && ($handle = fopen($path, 'r'))) {
      $header = fgetcsv($handle, escape: '');
      while (($values = fgetcsv($handle, escape: '')) !== FALSE) {
        yield array_combine($header, $values);
      }
      fclose($handle);
    }
  }

}
