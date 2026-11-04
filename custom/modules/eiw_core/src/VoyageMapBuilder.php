<?php

namespace Drupal\eiw_core;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\NodeInterface;

/**
 * Builds voyage maps: itineraries drawn as routes over an engraved base map.
 *
 * Rendered as a single static SVG (see eiw-voyage-map.html.twig): the base
 * map image (assets/voyage-map-base.svg) with each voyage's route drawn on
 * top as a smooth curve through its port calls, in itinerary order. The
 * outbound leg and the homeward leg (stops flagged field_homeward) are
 * drawn separately so they can be styled apart. Ports are placed from the
 * eiw_ports term's field_latitude/field_longitude; stops at a port without
 * coordinates are skipped.
 *
 * Itineraries list port calls, not the course sailed, so a curve straight
 * from one port to the next can cut across land. Two standard sea lanes
 * are built in (see seaLanes()): rounding the Cape between the Atlantic and
 * the Indian Ocean, and the swing out west of Africa on Atlantic legs.
 */
class VoyageMapBuilder {

  /**
   * Map bounds and scale, equirectangular.
   *
   * Keep in sync with scripts/build_voyage_basemap.py, which draws the base
   * map in this same coordinate space.
   */
  const LON_MIN = -80.0;
  const LON_MAX = 150.0;
  const LAT_MIN = -46.0;
  const LAT_MAX = 60.0;
  const SCALE = 5.0;

  /**
   * Number of route colours defined in the theme (.eiw-voyage-map--cN).
   */
  const COLOURS = 6;

  /**
   * Smallest map extent to zoom to, as a fraction of the full map width.
   */
  const MIN_ZOOM_WIDTH = 0.35;

  /**
   * Routing-only waypoints (lon, lat), shaping routes but not drawn.
   */
  const CAPE = [20.0, -37.0];
  const WEST_AFRICA_OFFING = [[-26.0, 12.0], [-22.0, -6.0]];

  public function __construct(
    protected ModuleExtensionList $moduleList,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Builds a map render array for one or more voyages.
   *
   * @param array $voyages
   *   A list of ['voyage' => NodeInterface, 'label' => string] items; the
   *   label names the route in the legend (defaults to the voyage title).
   *
   * @return array
   *   A render array, or an empty array if no voyage has a mappable stop.
   */
  public function build(array $voyages): array {
    $cache = new CacheableMetadata();
    $routes = [];
    $ports = [];

    foreach (array_values($voyages) as $index => $item) {
      /** @var \Drupal\node\NodeInterface $voyage */
      $voyage = $item['voyage'];
      $cache->addCacheableDependency($voyage);
      $legs = $this->legs($voyage, $cache);
      if (!$legs['outbound'] && !$legs['homeward']) {
        continue;
      }

      $route = [
        'index' => $index,
        'colour' => $index % static::COLOURS + 1,
        'label' => $item['label'] ?? $voyage->label(),
        'url' => $voyage->toUrl()->toString(),
        'legs' => [],
      ];
      foreach ($legs as $leg => $stops) {
        $points = array_map(fn($p) => $this->project(...$p), $this->seaLanes(array_column($stops, 'lonlat')));
        if (count($points) > 1) {
          $route['legs'][] = [
            'leg' => $leg,
            'path' => $this->smoothPath($points),
            'arrows' => $this->arrows($points),
          ];
        }
        foreach ($stops as $stop) {
          $key = $stop['tid'];
          $ports[$key] ??= [
            'name' => $stop['name'],
            'x' => round($stop['xy'][0], 1),
            'y' => round($stop['xy'][1], 1),
            'routes' => [],
            'calls' => [],
          ];
          $ports[$key]['routes'][$index] = $index;
          if ($stop['dates']) {
            $ports[$key]['calls'][] = (count($voyages) > 1 ? $route['label'] . ': ' : '') . $stop['dates'];
          }
        }
      }
      $routes[] = $route;
    }

    if (!$routes) {
      return [];
    }

    $viewbox = $this->viewBox(array_map(fn($p) => [$p['x'], $p['y']], $ports));
    $label_size = round($viewbox[2] / 60, 1);
    $this->placeLabels($ports, $label_size);

    $module_path = $this->moduleList->getPath('eiw_core');
    $build = [
      '#theme' => 'eiw_voyage_map',
      '#map_id' => 'eiw-voyage-map-' . substr(md5(implode('-', array_map(fn($i) => $i['voyage']->id(), $voyages))), 0, 8),
      '#routes' => $routes,
      '#ports' => array_values($ports),
      '#viewbox' => implode(' ', $viewbox),
      '#width' => (static::LON_MAX - static::LON_MIN) * static::SCALE,
      '#height' => (static::LAT_MAX - static::LAT_MIN) * static::SCALE,
      '#label_size' => $label_size,
      '#show_legend' => count($routes) > 1,
      '#has_homeward' => (bool) array_filter($routes, fn($r) => in_array('homeward', array_column($r['legs'], 'leg'), TRUE)),
      '#base_map' => $this->fileUrlGenerator->generateString($module_path . '/assets/voyage-map-base.svg'),
      '#attached' => ['library' => ['eiw_core/voyage_map']],
    ];
    $cache->applyTo($build);
    return $build;
  }

  /**
   * Splits a voyage's stops into projected outbound and homeward legs.
   *
   * The homeward leg starts from the last outbound stop, so the two lines
   * join up. Consecutive calls at the same port collapse into one point.
   */
  protected function legs(NodeInterface $voyage, CacheableMetadata $cache): array {
    $legs = ['outbound' => [], 'homeward' => []];
    if (!$voyage->hasField('field_voyage_stops')) {
      return $legs;
    }
    foreach ($voyage->get('field_voyage_stops')->referencedEntities() as $stop) {
      $port = $stop->get('field_port')->entity;
      if (!$port) {
        continue;
      }
      $cache->addCacheableDependency($port);
      $lat = $port->hasField('field_latitude') ? $port->get('field_latitude')->value : NULL;
      $lon = $port->hasField('field_longitude') ? $port->get('field_longitude')->value : NULL;
      if ($lat === NULL || $lon === NULL || $lat === '' || $lon === '') {
        continue;
      }
      $leg = $stop->get('field_homeward')->value ? 'homeward' : 'outbound';
      $point = [
        'tid' => $port->id(),
        'name' => $port->label(),
        'lonlat' => [(float) $lon, (float) $lat],
        'xy' => $this->project((float) $lon, (float) $lat),
        'dates' => implode(' – ', array_filter([
          $stop->get('field_arrival_date')->value,
          $stop->get('field_departure_date')->value,
        ])),
      ];
      $last = end($legs[$leg]);
      if ($last && $last['tid'] === $point['tid']) {
        continue;
      }
      if ($leg === 'homeward' && !$legs['homeward'] && $legs['outbound']) {
        $legs['homeward'][] = end($legs['outbound']);
      }
      $legs[$leg][] = $point;
    }
    return $legs;
  }

  /**
   * Adds sea-lane waypoints so legs go around Africa rather than across it.
   *
   * @param array $points
   *   [lon, lat] pairs in itinerary order.
   *
   * @return array
   *   The same points with waypoints inserted between them where needed.
   */
  protected function seaLanes(array $points): array {
    // East of Africa (Indian Ocean, Asia) vs. Atlantic/Europe side.
    $east = fn($p) => $p[0] >= 30 && $p[1] < 32;
    $west = fn($p) => $p[0] < 16;
    $near_cape = fn($p) => abs($p[0] - static::CAPE[0]) < 6 && abs($p[1] - static::CAPE[1]) < 6;
    $routed = [];
    foreach ($points as $i => $p) {
      $prev = $points[$i - 1] ?? NULL;
      if ($prev && !$near_cape($prev) && !$near_cape($p) && (($east($prev) && $west($p)) || ($west($prev) && $east($p)))) {
        $routed[] = static::CAPE;
      }
      $routed[] = $p;
    }

    // Atlantic legs between Europe (north of 20N) and the South Atlantic or
    // the Cape (south of 2N) swing out west of the African bulge.
    $out = [];
    foreach ($routed as $i => $p) {
      $prev = $routed[$i - 1] ?? NULL;
      if ($prev && $prev[0] < 25 && $p[0] < 25 && $prev[0] > -40 && $p[0] > -40) {
        if ($prev[1] > 20 && $p[1] < 2) {
          array_push($out, ...static::WEST_AFRICA_OFFING);
        }
        elseif ($prev[1] < 2 && $p[1] > 20) {
          array_push($out, ...array_reverse(static::WEST_AFRICA_OFFING));
        }
      }
      $out[] = $p;
    }
    return $out;
  }

  /**
   * Projects longitude/latitude onto the base map.
   */
  protected function project(float $lon, float $lat): array {
    return [
      ($lon - static::LON_MIN) * static::SCALE,
      (static::LAT_MAX - $lat) * static::SCALE,
    ];
  }

  /**
   * Bezier segments for a Catmull-Rom spline through the points.
   *
   * @return array
   *   One [p0, c1, c2, p3] array per leg between consecutive points.
   */
  protected function segments(array $points): array {
    $segments = [];
    $n = count($points);
    for ($i = 0; $i < $n - 1; $i++) {
      $p0 = $points[max($i - 1, 0)];
      $p1 = $points[$i];
      $p2 = $points[$i + 1];
      $p3 = $points[min($i + 2, $n - 1)];
      $segments[] = [
        $p1,
        [$p1[0] + ($p2[0] - $p0[0]) / 6, $p1[1] + ($p2[1] - $p0[1]) / 6],
        [$p2[0] - ($p3[0] - $p1[0]) / 6, $p2[1] - ($p3[1] - $p1[1]) / 6],
        $p2,
      ];
    }
    return $segments;
  }

  /**
   * An SVG path through the points, smoothed so routes read as arcs.
   */
  protected function smoothPath(array $points): string {
    $d = sprintf('M%.1f %.1f', ...$points[0]);
    foreach ($this->segments($points) as [, $c1, $c2, $p]) {
      $d .= sprintf('C%.1f %.1f %.1f %.1f %.1f %.1f', $c1[0], $c1[1], $c2[0], $c2[1], $p[0], $p[1]);
    }
    return $d;
  }

  /**
   * One direction arrow at the middle of each leg.
   *
   * @return array
   *   ['x', 'y', 'angle'] per leg, the angle in degrees along the curve.
   */
  protected function arrows(array $points): array {
    $arrows = [];
    foreach ($this->segments($points) as [$p0, $c1, $c2, $p3]) {
      // Point and tangent of the cubic Bezier at t = 0.5.
      $x = ($p0[0] + 3 * $c1[0] + 3 * $c2[0] + $p3[0]) / 8;
      $y = ($p0[1] + 3 * $c1[1] + 3 * $c2[1] + $p3[1]) / 8;
      $dx = $p3[0] + $c2[0] - $c1[0] - $p0[0];
      $dy = $p3[1] + $c2[1] - $c1[1] - $p0[1];
      if (hypot($p3[0] - $p0[0], $p3[1] - $p0[1]) < 25) {
        // Too short a leg for an arrow to read.
        continue;
      }
      $arrows[] = [
        'x' => round($x, 1),
        'y' => round($y, 1),
        'angle' => round(rad2deg(atan2($dy, $dx)), 1),
      ];
    }
    return $arrows;
  }

  /**
   * A viewBox fitted around the points, at the base map's aspect ratio.
   *
   * @return float[]
   *   [x, y, width, height].
   */
  protected function viewBox(array $points): array {
    $full_w = (static::LON_MAX - static::LON_MIN) * static::SCALE;
    $full_h = (static::LAT_MAX - static::LAT_MIN) * static::SCALE;
    $xs = array_column($points, 0);
    $ys = array_column($points, 1);
    $pad = 0.08 * $full_w;
    $w = max(max($xs) - min($xs) + 2 * $pad, static::MIN_ZOOM_WIDTH * $full_w);
    $h = max(max($ys) - min($ys) + 2 * $pad, $w * $full_h / $full_w);
    $w = min(max($w, $h * $full_w / $full_h), $full_w);
    $h = min($w * $full_h / $full_w, $full_h);
    $cx = (max($xs) + min($xs)) / 2;
    $cy = (max($ys) + min($ys)) / 2;
    $x = min(max($cx - $w / 2, 0), $full_w - $w);
    $y = min(max($cy - $h / 2, 0), $full_h - $h);
    return array_map(fn($v) => round($v, 1), [$x, $y, $w, $h]);
  }

  /**
   * Hides labels that would sit on top of an already-labelled nearby port.
   *
   * Ports are visited in itinerary order, so the first port of a cluster
   * (e.g. London before Gravesend and the Downs) keeps its label; the rest
   * still show their name on hover.
   */
  protected function placeLabels(array &$ports, float $label_size): void {
    $placed = [];
    foreach ($ports as &$port) {
      $port['label'] = TRUE;
      foreach ($placed as [$x, $y]) {
        if (abs($port['x'] - $x) < $label_size * 4 && abs($port['y'] - $y) < $label_size * 1.2) {
          $port['label'] = FALSE;
          break;
        }
      }
      if ($port['label']) {
        $placed[] = [$port['x'], $port['y']];
      }
    }
  }

}
