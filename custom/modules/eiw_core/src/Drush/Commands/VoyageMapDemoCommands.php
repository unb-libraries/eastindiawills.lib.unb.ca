<?php

namespace Drupal\eiw_core\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Creates (or removes) demo content for trying out voyage maps.
 *
 * Builds two voyages with outbound and homeward port calls, a ship for
 * each, and a will whose testator sailed on both - enough to see a single
 * voyage map (on each voyage) and the combined map with route toggles (on
 * the will). Demo nodes are titled "TEST ..." and the will's testator is
 * "Test Voyage-Map-Demo", so they're easy to spot and --delete can find
 * them again.
 *
 * Ports are real places, so they're matched by name against existing
 * eiw_ports terms rather than duplicated; coordinates are only filled in
 * where a port has none. Ports are kept on --delete, since their
 * coordinates are genuine and useful beyond the demo.
 *
 * Refuses to run where DEPLOY_ENV is "prod" unless --force is given.
 */
final class VoyageMapDemoCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Demo ports, as [latitude, longitude].
   */
  const PORTS = [
    'Gravesend' => [51.4416, 0.3683],
    'The Downs' => [51.23, 1.45],
    'London' => [51.5074, -0.1278],
    'Cape of Good Hope' => [-33.92, 18.42],
    'Johanna' => [-12.21, 44.43],
    'Madras' => [13.08, 80.27],
    'St Helena' => [-15.96, -5.71],
    'Bencoolen' => [-3.79, 102.27],
    'Canton' => [23.13, 113.26],
  ];

  /**
   * Demo voyages: title => [ship, stops].
   *
   * Each stop is [port, arrival, departure, homeward].
   */
  const VOYAGES = [
    'TEST 1750/51 Madras' => ['TEST Lord Anson', [
      ['Gravesend', NULL, '1750-12', FALSE],
      ['The Downs', '1750-12', '1751-01-03', FALSE],
      ['Cape of Good Hope', '1751-04', '1751-05', FALSE],
      ['Johanna', '1751-06', NULL, FALSE],
      ['Madras', '1751-08-14', '1752-01', FALSE],
      ['St Helena', '1752-05', NULL, TRUE],
      ['The Downs', '1752-08', NULL, TRUE],
    ]],
    'TEST 1754/55 China' => ['TEST Hardwicke', [
      ['The Downs', NULL, '1754-02', FALSE],
      ['Cape of Good Hope', '1754-05', NULL, FALSE],
      ['Bencoolen', '1754-08', NULL, FALSE],
      ['Canton', '1754-10', '1755-01', FALSE],
      ['St Helena', '1755-05', NULL, TRUE],
      ['London', '1755-08', NULL, TRUE],
    ]],
  ];

  const TESTATOR_FIRST = 'Test';
  const TESTATOR_LAST = 'Voyage-Map-Demo';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Creates demo voyages and a will for trying out voyage maps.
   */
  #[CLI\Command(name: 'eiw:voyage-map-demo', aliases: ['eiw-vmd'])]
  #[CLI\Option(name: 'delete', description: 'Remove the demo voyages, ships and will (ports are kept).')]
  #[CLI\Option(name: 'force', description: 'Allow running where DEPLOY_ENV is "prod".')]
  #[CLI\Usage(name: 'drush eiw:voyage-map-demo', description: 'Create the demo content.')]
  #[CLI\Usage(name: 'drush eiw:voyage-map-demo --delete', description: 'Remove it again.')]
  public function voyageMapDemo(array $options = ['delete' => FALSE, 'force' => FALSE]): void {
    if (getenv('DEPLOY_ENV') === 'prod' && !$options['force']) {
      throw new \RuntimeException('Refusing to touch demo content in production (DEPLOY_ENV=prod); pass --force to override.');
    }
    if ($options['delete']) {
      $this->deleteDemo();
      return;
    }
    if ($this->demoWills()) {
      $this->logger()->warning('Demo content already exists; run with --delete first to recreate it.');
      return;
    }
    $this->createDemo();
  }

  /**
   * Creates the ports (or fills in their coordinates), voyages and will.
   */
  protected function createDemo(): void {
    $terms = $this->entityTypeManager->getStorage('taxonomy_term');
    $nodes = $this->entityTypeManager->getStorage('node');
    $paragraphs = $this->entityTypeManager->getStorage('paragraph');

    $ports = [];
    foreach (static::PORTS as $name => [$lat, $lon]) {
      $existing = $terms->loadByProperties(['vid' => 'eiw_ports', 'name' => $name]);
      $port = reset($existing) ?: $terms->create(['vid' => 'eiw_ports', 'name' => $name]);
      if ($port->get('field_latitude')->isEmpty() || $port->get('field_longitude')->isEmpty()) {
        $port->set('field_latitude', $lat)->set('field_longitude', $lon)->save();
        $this->logger()->notice(($existing ? 'Added coordinates to port' : 'Created port') . " $name.");
      }
      $ports[$name] = $port->id();
    }

    $will_voyages = [];
    foreach (static::VOYAGES as $title => [$ship_title, $stops]) {
      $stop_items = [];
      foreach ($stops as [$port, $arrival, $departure, $homeward]) {
        $stop = $paragraphs->create([
          'type' => 'eiw_voyage_stop',
          'field_port' => $ports[$port],
          'field_arrival_date' => $arrival,
          'field_departure_date' => $departure,
          'field_homeward' => $homeward,
        ]);
        $stop->save();
        $stop_items[] = ['target_id' => $stop->id(), 'target_revision_id' => $stop->getRevisionId()];
      }
      $voyage = $nodes->create(['type' => 'eiw_voyage', 'title' => $title, 'field_voyage_stops' => $stop_items]);
      $voyage->save();
      $ship = $nodes->create(['type' => 'eiw_ship', 'title' => $ship_title]);
      $ship->save();
      $will_voyage = $paragraphs->create([
        'type' => 'eiw_will_voyage',
        'field_voyage' => $voyage->id(),
        'field_ship' => $ship->id(),
      ]);
      $will_voyage->save();
      $will_voyages[] = ['target_id' => $will_voyage->id(), 'target_revision_id' => $will_voyage->getRevisionId()];
      $this->logger()->success("Created voyage $title: " . $voyage->toUrl()->toString());
    }

    $will = $nodes->create([
      'type' => 'eiw_will',
      // Replaced by eiw_core_node_presave() from the testator's name.
      'title' => static::TESTATOR_LAST,
      'field_first_name' => static::TESTATOR_FIRST,
      'field_last_name' => static::TESTATOR_LAST,
      'field_will_voyages' => $will_voyages,
    ]);
    $will->save();
    $this->logger()->success('Created will ' . $will->label() . ' (combined map): ' . $will->toUrl()->toString());
  }

  /**
   * Deletes the demo will, voyages and ships; their paragraphs go with them.
   */
  protected function deleteDemo(): void {
    $nodes = $this->entityTypeManager->getStorage('node');
    $titles = array_keys(static::VOYAGES);
    foreach (static::VOYAGES as [$ship_title]) {
      $titles[] = $ship_title;
    }
    $demo = $this->demoWills() + $nodes->loadByProperties(['title' => $titles]);
    foreach ($demo as $node) {
      $this->logger()->notice('Deleting ' . $node->bundle() . ' ' . $node->label() . '.');
    }
    $nodes->delete($demo);
    $this->logger()->success(count($demo) . ' demo nodes deleted; ports kept.');
  }

  /**
   * Loads the demo will(s), found by the testator's name.
   */
  protected function demoWills(): array {
    return $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'eiw_will',
      'field_first_name' => static::TESTATOR_FIRST,
      'field_last_name' => static::TESTATOR_LAST,
    ]);
  }

}
