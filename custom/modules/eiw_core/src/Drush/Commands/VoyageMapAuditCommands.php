<?php

namespace Drupal\eiw_core\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\eiw_core\VoyageMapBuilder;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks voyage maps for routes drawn across land or outside the map frame.
 *
 * Each voyage's route is drawn exactly as the map draws it (see
 * VoyageMapBuilder::geometry()), sampled along the curve and tested against
 * the land outline in the base map. Land near the voyage's own ports is
 * ignored: ports sit on the coast, or up rivers, and the base map's
 * coastline is coarse.
 */
final class VoyageMapAuditCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Land within this distance of a port of call is ignored (map units).
   */
  const PORT_RADIUS = 12.5;

  /**
   * Land runs shorter than this are ignored as grazing the coast (map units).
   */
  const MIN_RUN = 5.0;

  /**
   * Routes between two stops longer than this many times the shortest route
   * at sea, and by at least DETOUR_MIN degrees, are reported as detours.
   */
  const DETOUR_RATIO = 1.5;
  const DETOUR_MIN = 10.0;

  /**
   * Land rings from the base map, as [bbox, points].
   */
  protected array $land = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'eiw_core.voyage_map_builder')]
    protected VoyageMapBuilder $mapBuilder,
    protected ModuleExtensionList $moduleList,
  ) {
    parent::__construct();
  }

  /**
   * Lists voyage map routes that cross land or leave the map frame.
   */
  #[CLI\Command(name: 'eiw:voyage-map-audit', aliases: ['eiw-vma'])]
  #[CLI\Option(name: 'nid', description: 'Only check these voyages (comma-separated node IDs).')]
  #[CLI\FieldLabels(labels: [
    'nid' => 'Nid',
    'voyage' => 'Voyage',
    'leg' => 'Leg',
    'between' => 'Between',
    'problem' => 'Problem',
    'where' => 'Where (lon, lat)',
  ])]
  #[CLI\Usage(name: 'drush eiw:voyage-map-audit', description: 'Check every voyage map.')]
  #[CLI\Usage(name: 'drush eiw-vma --nid=272,396', description: 'Check two voyages.')]
  public function audit(array $options = ['nid' => self::REQ, 'format' => 'table']): RowsOfFields {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'eiw_voyage')->sort('title');
    if ($options['nid']) {
      $query->condition('nid', array_map('intval', explode(',', $options['nid'])), 'IN');
    }
    $rows = [];
    $checked = 0;
    foreach ($storage->loadMultiple($query->execute()) as $voyage) {
      $geometry = $this->mapBuilder->geometry($voyage);
      if (!$geometry['legs']) {
        continue;
      }
      $checked++;
      foreach ($this->problems($geometry) as $problem) {
        $rows[] = ['nid' => $voyage->id(), 'voyage' => $voyage->label()] + $problem;
      }
    }
    $voyages = count(array_unique(array_column($rows, 'nid')));
    $this->logger()->notice("Checked $checked mapped voyages; $voyages with problems, " . count($rows) . ' in all.');
    return new RowsOfFields($rows);
  }

  /**
   * Finds land crossings and out-of-frame stretches in one voyage's geometry.
   */
  protected function problems(array $geometry): array {
    $problems = [];
    $ports = [];
    foreach ($geometry['legs'] as $leg) {
      foreach ($leg['points'] as [$x, $y, $name]) {
        if ($name !== NULL) {
          $ports[] = [$x, $y];
        }
      }
    }
    [$vx, $vy, $vw, $vh] = $geometry['viewbox'];

    foreach ($geometry['legs'] as $leg) {
      // Detours: route length between consecutive stops vs. the shortest
      // route at sea between them.
      $last = NULL;
      $length = 0;
      foreach ($leg['points'] as $i => $point) {
        if ($i > 0) {
          $length += hypot($point[0] - $leg['points'][$i - 1][0], $point[1] - $leg['points'][$i - 1][1]);
        }
        if ($point[2] === NULL) {
          continue;
        }
        if ($last !== NULL) {
          $sea = $this->mapBuilder->shortestSeaRoute(
            $this->mapBuilder->unproject($leg['points'][$last][0], $leg['points'][$last][1]),
            $this->mapBuilder->unproject($point[0], $point[1]),
          );
          $direct = 0;
          for ($k = 1; $k < count($sea); $k++) {
            $direct += hypot($sea[$k][0] - $sea[$k - 1][0], $sea[$k][1] - $sea[$k - 1][1]) * 5;
          }
          if ($length > static::DETOUR_RATIO * $direct && $length - $direct > static::DETOUR_MIN * 5) {
            [$lon, $lat] = $this->mapBuilder->unproject($point[0], $point[1]);
            $problems[] = [
              'leg' => $leg['leg'],
              'between' => $leg['points'][$last][2] . ' → ' . $point[2],
              'problem' => sprintf('long detour (%.0f° where %.0f° would do)', $length / 5, $direct / 5),
              'where' => sprintf('%.1f, %.1f', $lon, $lat),
            ];
          }
        }
        $last = $i;
        $length = 0;
      }

      $runs = ['land' => [], 'outside the map frame' => []];
      foreach ($leg['samples'] as $sample) {
        [$x, $y] = $sample;
        $runs['outside the map frame'][] = ($x < $vx - 1 || $x > $vx + $vw + 1 || $y < $vy - 1 || $y > $vy + $vh + 1) ? $sample : NULL;
        $runs['land'][] = (!$this->nearAny($x, $y, $ports) && $this->onLand($x, $y)) ? $sample : NULL;
      }
      foreach ($runs as $kind => $flags) {
        foreach ($this->runs($flags) as $run) {
          if ($kind === 'land' && $this->length($run) < static::MIN_RUN) {
            continue;
          }
          $mid = $run[intdiv(count($run), 2)];
          [$lon, $lat] = $this->mapBuilder->unproject($mid[0], $mid[1]);
          $problems[] = [
            'leg' => $leg['leg'],
            'between' => $this->between($leg['points'], $mid[2]),
            'problem' => $kind === 'land' ? sprintf('crosses land (%.0f°)', $this->length($run) / 5) : $kind,
            'where' => sprintf('%.1f, %.1f', $lon, $lat),
          ];
        }
      }
    }
    return $problems;
  }

  /**
   * Splits a list of samples-or-NULL into runs of consecutive samples.
   */
  protected function runs(array $flags): array {
    $runs = [];
    $current = [];
    foreach ($flags as $sample) {
      if ($sample) {
        $current[] = $sample;
      }
      elseif ($current) {
        $runs[] = $current;
        $current = [];
      }
    }
    if ($current) {
      $runs[] = $current;
    }
    return $runs;
  }

  /**
   * Length of a run of samples, in map units.
   */
  protected function length(array $run): float {
    $length = 0;
    for ($i = 1; $i < count($run); $i++) {
      $length += hypot($run[$i][0] - $run[$i - 1][0], $run[$i][1] - $run[$i - 1][1]);
    }
    return $length;
  }

  /**
   * Names the stops either side of a segment (waypoints are skipped).
   */
  protected function between(array $points, int $segment): string {
    $from = $to = '?';
    for ($i = $segment; $i >= 0; $i--) {
      if ($points[$i][2] !== NULL) {
        $from = $points[$i][2];
        break;
      }
    }
    for ($i = $segment + 1; $i < count($points); $i++) {
      if ($points[$i][2] !== NULL) {
        $to = $points[$i][2];
        break;
      }
    }
    return "$from → $to";
  }

  /**
   * Whether a point is within PORT_RADIUS of any of the given points.
   */
  protected function nearAny(float $x, float $y, array $points): bool {
    foreach ($points as [$px, $py]) {
      if (hypot($x - $px, $y - $py) < static::PORT_RADIUS) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a point is on land in the base map (even-odd over all rings).
   */
  protected function onLand(float $x, float $y): bool {
    $inside = FALSE;
    foreach ($this->land() as [[$x0, $y0, $x1, $y1], $ring]) {
      if ($x < $x0 || $x > $x1 || $y < $y0 || $y > $y1) {
        continue;
      }
      $n = count($ring);
      for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$xi, $yi] = $ring[$i];
        [$xj, $yj] = $ring[$j];
        if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
          $inside = !$inside;
        }
      }
    }
    return $inside;
  }

  /**
   * Parses the land outline (the "land" path) from the base map.
   */
  protected function land(): array {
    if (!$this->land) {
      $svg = file_get_contents($this->moduleList->getPath('eiw_core') . '/assets/voyage-map-base.svg');
      preg_match('/<path id="land" d="([^"]*)"/', $svg, $match);
      foreach (array_filter(explode('M', $match[1])) as $ring) {
        $numbers = array_map('floatval', preg_split('/[\s,]+/', trim(str_replace('Z', '', $ring))));
        $points = array_chunk($numbers, 2);
        $xs = array_column($points, 0);
        $ys = array_column($points, 1);
        $this->land[] = [[min($xs), min($ys), max($xs), max($ys)], $points];
      }
    }
    return $this->land;
  }

}
