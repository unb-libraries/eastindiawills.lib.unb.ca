<?php

namespace Drupal\eiw_core\Drush\Commands;

use Drupal\Core\Database\Connection;
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
 * where a port has none. On --delete, a demo port is removed only if no
 * remaining voyage stop uses it; ports real voyages use are kept.
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
    protected Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Creates demo voyages and a will for trying out voyage maps.
   */
  #[CLI\Command(name: 'eiw:voyage-map-demo', aliases: ['eiw-vmd'])]
  #[CLI\Option(name: 'delete', description: 'Remove the demo voyages, ships, will, their paragraphs, and demo ports nothing else uses.')]
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
   * Deletes the demo will, voyages and ships, their paragraphs, and demo ports
   * that no voyage stop uses any more.
   *
   * Starts from the demo will(s) and the exact demo titles (by content type),
   * then follows references: will -> will-voyage paragraphs -> voyages and
   * ships; voyage -> stop paragraphs. The paragraph fields are plain entity
   * references, so paragraphs are deleted explicitly rather than left orphaned.
   * Lists everything and asks for confirmation first (-y to skip).
   */
  protected function deleteDemo(): void {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    $term_storage = $this->entityTypeManager->getStorage('taxonomy_term');

    $nodes = $this->demoWills();
    $nodes += $node_storage->loadByProperties(['type' => 'eiw_voyage', 'title' => array_keys(static::VOYAGES)]);
    $nodes += $node_storage->loadByProperties(['type' => 'eiw_ship', 'title' => array_column(static::VOYAGES, 0)]);

    $paragraphs = [];
    foreach ($this->demoWills() as $will) {
      foreach ($will->get('field_will_voyages')->referencedEntities() as $will_voyage) {
        $paragraphs[$will_voyage->id()] = $will_voyage;
        foreach (['field_voyage', 'field_ship'] as $field) {
          foreach ($will_voyage->get($field)->referencedEntities() as $node) {
            // Only follow into demo content, never into real voyages or ships.
            if (in_array($node->label(), array_merge(array_keys(static::VOYAGES), array_column(static::VOYAGES, 0)), TRUE)) {
              $nodes[$node->id()] = $node;
            }
          }
        }
      }
    }
    $port_ids = [];
    foreach ($nodes as $node) {
      if ($node->bundle() === 'eiw_voyage') {
        foreach ($node->get('field_voyage_stops')->referencedEntities() as $stop) {
          $paragraphs[$stop->id()] = $stop;
          $port_ids[$stop->get('field_port')->target_id] = TRUE;
        }
      }
    }

    // Leftovers of earlier demo deletes, which removed nodes but not their
    // paragraphs: paragraphs no node references any more.
    $demo_ports = $term_storage->loadByProperties(['vid' => 'eiw_ports', 'name' => array_keys(static::PORTS)]);
    foreach ($paragraph_storage->loadMultiple($this->orphanedDemoParagraphIds(array_keys($demo_ports))) as $orphan) {
      $paragraphs[$orphan->id()] = $orphan;
    }

    // Demo ports that nothing but the demo's own stops uses.
    $ports = [];
    foreach ($demo_ports as $port) {
      $users = $paragraph_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'eiw_voyage_stop')
        ->condition('field_port', $port->id())
        ->execute();
      if (!array_diff($users, array_keys($paragraphs))) {
        $ports[$port->id()] = $port;
      }
    }

    if (!$nodes && !$paragraphs && !$ports) {
      $this->logger()->success('No demo content found.');
      return;
    }
    foreach ($nodes as $node) {
      $this->io()->writeln(sprintf('  node %d  %s  %s', $node->id(), $node->bundle(), $node->label()));
    }
    $this->io()->writeln(sprintf('  %d paragraphs (voyage stops and will voyages, including orphans of earlier deletes)', count($paragraphs)));
    foreach ($ports as $port) {
      $this->io()->writeln(sprintf('  term %d  eiw_ports  %s (unused once the demo is gone)', $port->id(), $port->label()));
    }
    if (!$this->io()->confirm('Delete the demo content listed above?', FALSE)) {
      throw new \Drush\Exceptions\UserAbortException();
    }

    $node_storage->delete($nodes);
    $paragraph_storage->delete($paragraphs);
    $term_storage->delete($ports);
    $this->logger()->success(sprintf('Deleted %d demo nodes, %d paragraphs and %d unused demo ports.',
      count($nodes), count($paragraphs), count($ports)));
  }

  /**
   * Finds paragraphs orphaned by earlier demo deletes.
   *
   * A voyage stop counts only if no voyage references it, its port is a demo
   * port, and it isn't a migrated stop (eiw_3_voyage_stops creates stops
   * before eiw_4_voyages attaches them). A will voyage counts only if no will
   * references it and its voyage no longer exists.
   *
   * @param int[] $port_ids
   *   The demo port term IDs.
   *
   * @return int[]
   *   Paragraph IDs.
   */
  protected function orphanedDemoParagraphIds(array $port_ids): array {
    $ids = [];
    if ($port_ids) {
      $stops = $this->database->select('paragraphs_item_field_data', 'p');
      $stops->join('paragraph__field_port', 'fp', 'fp.entity_id = p.id');
      $stops->fields('p', ['id'])
        ->condition('p.type', 'eiw_voyage_stop')
        ->condition('fp.field_port_target_id', $port_ids, 'IN');
      $referenced = $this->database->select('node__field_voyage_stops', 'r')->fields('r', ['field_voyage_stops_target_id']);
      $stops->condition('p.id', $referenced, 'NOT IN');
      if ($this->database->schema()->tableExists('migrate_map_eiw_3_voyage_stops')) {
        $migrated = $this->database->select('migrate_map_eiw_3_voyage_stops', 'm')->fields('m', ['destid1'])->isNotNull('m.destid1');
        $stops->condition('p.id', $migrated, 'NOT IN');
      }
      $ids = $stops->execute()->fetchCol();
    }

    $will_voyages = $this->database->select('paragraphs_item_field_data', 'p');
    $will_voyages->leftJoin('paragraph__field_voyage', 'fv', 'fv.entity_id = p.id');
    $will_voyages->leftJoin('node', 'n', 'n.nid = fv.field_voyage_target_id');
    $will_voyages->fields('p', ['id'])
      ->condition('p.type', 'eiw_will_voyage')
      ->isNull('n.nid');
    $referenced = $this->database->select('node__field_will_voyages', 'r')->fields('r', ['field_will_voyages_target_id']);
    $will_voyages->condition('p.id', $referenced, 'NOT IN');

    return array_merge($ids, $will_voyages->execute()->fetchCol());
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
