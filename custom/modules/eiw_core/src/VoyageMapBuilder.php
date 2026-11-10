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
 * from one port to the next can cut across land. Standard sea lanes are
 * built in (see seaLanes()): rounding the Cape between the Atlantic and the
 * Indian Ocean, the swing out west of Africa on Atlantic legs (round Cape
 * Palmas for the Guinea coast), and rounding Ceylon between the Arabian Sea
 * and the Bay of Bengal. The view is fitted to the drawn routes.
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
  const CAPE_PALMAS_OFFING = [-12.0, 2.5];
  const CEYLON = [80.5, 5.0];

  /**
   * Points at sea (lon, lat) off capes and in straits, for routing round land.
   *
   * Where a leg drawn straight would cross land (by the land mask built from
   * the base map), the route takes the shortest way through these instead.
   */
  const SEA_WAYPOINTS = [
    'North Foreland' => [1.7, 51.45],
    'Dover Strait' => [1.5, 51.0],
    'off the Isle of Wight' => [-1.3, 50.4],
    'off the Lizard' => [-5.3, 49.7],
    'off Ushant' => [-6.0, 48.5],
    'off Cape Clear' => [-9.5, 51.0],
    'off Finisterre' => [-10.5, 43.0],
    'off Cape St Vincent' => [-10.0, 36.8],
    'west of the Canaries' => [-19.0, 28.5],
    'off Cape Verde' => [-18.5, 14.5],
    'off Cape Palmas' => [-8.0, 3.5],
    'Bight of Benin' => [3.0, 4.0],
    'off Cape Lopez' => [8.0, -1.0],
    'off Angola' => [11.0, -12.0],
    'off the Cape' => [20.0, -37.0],
    'off Cape Agulhas' => [27.0, -35.5],
    'off Natal' => [33.0, -30.5],
    'south of Madagascar' => [46.0, -27.5],
    'Mozambique Channel' => [41.0, -20.0],
    'north Mozambique Channel' => [42.8, -14.5],
    'off Cape Amber' => [50.0, -11.5],
    'east of Madagascar' => [52.0, -20.0],
    'off Cape Guardafui' => [52.5, 12.0],
    'Bab-el-Mandeb' => [43.4, 12.6],
    'Red Sea' => [39.0, 20.0],
    'north of Socotra' => [54.0, 13.4],
    'off Dhofar' => [56.5, 16.3],
    'off Masirah' => [59.8, 19.8],
    'off Ras al-Hadd' => [60.5, 22.5],
    'Arabian Sea' => [63.0, 16.0],
    'Indian Ocean' => [75.0, -5.0],
    'south Indian Ocean' => [70.0, -25.0],
    'Bay of Bengal' => [88.0, 12.0],
    'South China Sea' => [113.0, 15.0],
    'Strait of Hormuz' => [56.6, 26.4],
    'Persian Gulf' => [54.0, 26.0],
    'upper Persian Gulf' => [49.8, 28.2],
    'off the Shatt al-Arab' => [48.9, 29.7],
    'off Kathiawar' => [69.0, 21.0],
    'off Diu' => [70.8, 20.2],
    'Gulf of Cambay' => [72.2, 20.3],
    'off Bombay' => [72.3, 18.5],
    'off Malabar' => [73.0, 13.0],
    'off Cape Comorin' => [77.5, 7.0],
    'south of Ceylon' => [80.5, 5.3],
    'east of Ceylon' => [83.0, 7.5],
    'off Coromandel' => [81.5, 14.0],
    'off Masulipatam' => [82.0, 15.8],
    'off Vizagapatam' => [84.0, 17.0],
    'off Orissa' => [86.8, 19.3],
    'head of the Bay of Bengal' => [88.0, 20.5],
    'Andaman Sea' => [96.0, 10.0],
    'off Achin Head' => [95.0, 6.2],
    'west of Sumatra' => [96.0, 0.5],
    'south-west of Sumatra' => [100.5, -4.0],
    'Malacca Strait north' => [99.0, 4.5],
    'Malacca Strait south' => [101.5, 2.4],
    'Singapore Strait' => [104.0, 1.2],
    'east of Singapore' => [105.5, 2.5],
    'off Pahang' => [104.5, 4.0],
    'Gaspar Strait' => [107.1, -2.6],
    'Gulf of Siam' => [101.5, 10.0],
    'head of the Gulf of Siam' => [100.6, 12.8],
    'off Cape Cambodia' => [105.0, 7.5],
    'off Pulo Condore' => [107.5, 9.0],
    'off Cape Padaran' => [110.0, 11.5],
    'Gulf of Tonkin' => [107.5, 19.5],
    'south of Hainan' => [110.0, 17.5],
    'off Canton' => [114.0, 21.8],
    'west of Luzon' => [119.0, 15.0],
    'Formosa Strait' => [119.5, 23.5],
    'west of Kyushu' => [128.5, 32.0],
    'west of the Sunda Strait' => [105.0, -6.5],
    'Sunda Strait' => [105.85, -5.95],
    'Java Sea west' => [107.5, -5.0],
    'Karimata Strait' => [109.0, -2.0],
    'Java Sea east' => [113.5, -5.5],
    'Makassar Strait' => [117.8, -2.0],
    'Flores Sea' => [121.0, -7.0],
    'Banda Sea' => [127.0, -5.5],
    'south of Ambon' => [128.2, -4.2],
    'Molucca Sea' => [126.5, 0.5],
  ];

  /**
   * Land near a port is ignored when routing (degrees): ports are on the
   * coast or up rivers, and the base map's coastline is coarse.
   */
  const PORT_CLEARANCE = 1.5;

  /**
   * The same for sea waypoints, which sit in narrow straits (degrees).
   */
  const WAYPOINT_CLEARANCE = 0.5;

  /**
   * Routing cost per waypoint used (degrees), so routes don't zigzag.
   */
  const WAYPOINT_COST = 2.0;

  /**
   * The land mask (see scripts/build_voyage_landmask.py), one bit per unit.
   */
  protected ?string $landMask = NULL;

  /**
   * Memoised sea-waypoint pairs: "i-j" => whether the straight line is clear.
   */
  protected array $waypointClear = [];

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
    // Points along the drawn routes, so the zoom fits the curves (which bend
    // out through sea-lane waypoints) and not just the ports.
    $route_points = [];

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
          array_push($route_points, ...$this->curvePoints($points));
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

    $viewbox = $this->viewBox(array_merge(array_map(fn($p) => [$p['x'], $p['y']], $ports), $route_points));
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
   * The geometry a voyage's map draws, for checking routes (see
   * VoyageMapAuditCommands): per leg, the routed points (stops and sea-lane
   * waypoints, projected) and points sampled along the drawn curve; plus the
   * view box the map is fitted to.
   *
   * @param \Drupal\node\NodeInterface $voyage
   *   The voyage.
   * @param float $step
   *   Rough spacing of the curve samples, in map units (5 per degree).
   *
   * @return array
   *   ['legs' => [['leg', 'stops' => [name, ...], 'points' => [[x, y,
   *   name|NULL], ...], 'samples' => [[x, y, segment], ...]], ...],
   *   'viewbox' => [x, y, w, h]].
   */
  public function geometry(NodeInterface $voyage, float $step = 1.0): array {
    $legs = [];
    $fit = [];
    foreach ($this->legs($voyage, new CacheableMetadata()) as $leg => $stops) {
      foreach ($stops as $stop) {
        $fit[] = $stop['xy'];
      }
      $routed = $this->seaLanes(array_column($stops, 'lonlat'));
      $names = [];
      $next = 0;
      foreach ($routed as $lonlat) {
        // Sea-lane waypoints are new points; stops come through unchanged.
        $is_stop = isset($stops[$next]) && $stops[$next]['lonlat'] === $lonlat;
        $names[] = $is_stop ? $stops[$next++]['name'] : NULL;
      }
      $points = array_map(fn($p) => $this->project(...$p), $routed);
      if (count($points) < 2) {
        continue;
      }
      array_push($fit, ...$this->curvePoints($points));
      $samples = [];
      foreach ($this->segments($points) as $i => [$p0, $c1, $c2, $p3]) {
        $n = max(2, (int) ceil(hypot($p3[0] - $p0[0], $p3[1] - $p0[1]) / $step));
        for ($k = 0; $k <= $n; $k++) {
          $t = $k / $n;
          $u = 1 - $t;
          $samples[] = [
            $u ** 3 * $p0[0] + 3 * $u ** 2 * $t * $c1[0] + 3 * $u * $t ** 2 * $c2[0] + $t ** 3 * $p3[0],
            $u ** 3 * $p0[1] + 3 * $u ** 2 * $t * $c1[1] + 3 * $u * $t ** 2 * $c2[1] + $t ** 3 * $p3[1],
            $i,
          ];
        }
      }
      $legs[] = [
        'leg' => $leg,
        'stops' => array_column($stops, 'name'),
        'points' => array_map(fn($p, $name) => [$p[0], $p[1], $name], $points, $names),
        'samples' => $samples,
      ];
    }
    return ['legs' => $legs, 'viewbox' => $fit ? $this->viewBox($fit) : NULL];
  }

  /**
   * Converts map coordinates back to [lon, lat].
   */
  public function unproject(float $x, float $y): array {
    return [$x / static::SCALE + static::LON_MIN, static::LAT_MAX - $y / static::SCALE];
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
   * Adds sea-lane waypoints so legs go around Africa and India rather than
   * across them.
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

    // Legs between the Arabian Sea side of India (and Persia, Arabia, East
    // Africa north of the equator) and the Bay of Bengal or further east
    // round Ceylon rather than crossing the peninsula.
    $arabian = fn($p) => $p[0] > 40 && $p[0] < 78 && $p[1] > 5;
    $bengal = fn($p) => $p[0] > 79.5 && $p[1] > -12;
    $rounded = [];
    foreach ($routed as $i => $p) {
      $prev = $routed[$i - 1] ?? NULL;
      if ($prev && (($arabian($prev) && $bengal($p)) || ($bengal($prev) && $arabian($p)))) {
        $rounded[] = static::CEYLON;
      }
      $rounded[] = $p;
    }
    $routed = $rounded;

    // Atlantic legs between Europe (north of 20N) and the South Atlantic or
    // the Cape (south of 2N) swing out west of the African bulge. Legs to the
    // Guinea coast (2-12N, east of the bulge) round the bulge too, and, east
    // of Cape Palmas, pass south of it.
    $guinea = fn($p) => $p[1] >= 2 && $p[1] < 12 && $p[0] > -17 && $p[0] < 12;
    $offing = function ($p) use ($guinea): array {
      if ($guinea($p)) {
        return $p[0] > -10 ? [static::WEST_AFRICA_OFFING[0], static::CAPE_PALMAS_OFFING] : [static::WEST_AFRICA_OFFING[0]];
      }
      return $p[1] < 2 ? static::WEST_AFRICA_OFFING : [];
    };
    $out = [];
    foreach ($routed as $i => $p) {
      $prev = $routed[$i - 1] ?? NULL;
      if ($prev && $prev[0] < 25 && $p[0] < 25 && $prev[0] > -40 && $p[0] > -40) {
        if ($prev[1] > 20) {
          array_push($out, ...$offing($p));
        }
        elseif ($p[1] > 20) {
          array_push($out, ...array_reverse($offing($prev)));
        }
      }
      $out[] = $p;
    }

    // Wherever a leg still crosses land, route it round through sea waypoints.
    if (!$out) {
      return [];
    }
    $routed = [$out[0]];
    for ($i = 1; $i < count($out); $i++) {
      array_push($routed, ...$this->routeAtSea($out[$i - 1], $out[$i]));
      $routed[] = $out[$i];
    }
    return $routed;
  }

  /**
   * Waypoints for a leg that would cross land drawn straight; none if clear.
   *
   * Shortest path (Dijkstra) from $a to $b through SEA_WAYPOINTS, using only
   * straight lines that stay at sea. If there is no such path, the leg is left
   * straight; drush eiw:voyage-map-audit reports it as crossing land, and the
   * fix is a waypoint where the port meets open water.
   *
   * @param float[] $a
   *   [lon, lat] of the leg's start.
   * @param float[] $b
   *   [lon, lat] of the leg's end.
   *
   * @return array
   *   [lon, lat] waypoints to insert between $a and $b.
   */
  protected function routeAtSea(array $a, array $b): array {
    if ($this->clearAtSea($a, $b, static::PORT_CLEARANCE, static::PORT_CLEARANCE)) {
      return [];
    }
    // Nodes: 0 = start, 1 = end, then the waypoints.
    $nodes = array_merge([$a, $b], array_values(static::SEA_WAYPOINTS));
    $n = count($nodes);
    $dist = array_fill(0, $n, INF);
    $from = array_fill(0, $n, NULL);
    $done = [];
    $dist[0] = 0;
    while (TRUE) {
      $u = NULL;
      foreach ($dist as $i => $d) {
        if (!isset($done[$i]) && $d < INF && ($u === NULL || $d < $dist[$u])) {
          $u = $i;
        }
      }
      if ($u === NULL || $u === 1) {
        break;
      }
      $done[$u] = TRUE;
      foreach ($nodes as $v => $point) {
        if (isset($done[$v]) || $v === 0) {
          continue;
        }
        $cost = $dist[$u] + hypot($point[0] - $nodes[$u][0], $point[1] - $nodes[$u][1]) + ($v > 1 ? static::WAYPOINT_COST : 0);
        if ($cost < $dist[$v] && $this->edgeAtSea($u, $v, $nodes)) {
          $dist[$v] = $cost;
          $from[$v] = $u;
        }
      }
    }
    if ($from[1] === NULL) {
      return [];
    }
    $path = [];
    for ($v = $from[1]; $v !== 0; $v = $from[$v]) {
      array_unshift($path, $nodes[$v]);
    }
    return $path;
  }

  /**
   * Whether the straight line between two routing nodes stays at sea.
   */
  protected function edgeAtSea(int $u, int $v, array $nodes): bool {
    $clearance = fn($i) => $i < 2 ? static::PORT_CLEARANCE : static::WAYPOINT_CLEARANCE;
    if ($u < 2 || $v < 2) {
      return $this->clearAtSea($nodes[$u], $nodes[$v], $clearance($u), $clearance($v));
    }
    $key = min($u, $v) . '-' . max($u, $v);
    return $this->waypointClear[$key] ??= $this->clearAtSea($nodes[$u], $nodes[$v], static::WAYPOINT_CLEARANCE, static::WAYPOINT_CLEARANCE);
  }

  /**
   * Whether a straight line (lon/lat) stays at sea, ignoring land near its ends.
   */
  protected function clearAtSea(array $a, array $b, float $clear_a, float $clear_b): bool {
    $length = hypot($b[0] - $a[0], $b[1] - $a[1]);
    $steps = (int) ceil($length / 0.2);
    for ($k = 1; $k < $steps; $k++) {
      $t = $k / $steps;
      if ($t * $length < $clear_a || (1 - $t) * $length < $clear_b) {
        continue;
      }
      if ($this->isLand($a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether a point (lon/lat) is land in the land mask.
   */
  protected function isLand(float $lon, float $lat): bool {
    $this->landMask ??= (string) file_get_contents($this->moduleList->getPath('eiw_core') . '/assets/voyage-map-land.bin');
    [$x, $y] = $this->project($lon, $lat);
    $width = (int) ((static::LON_MAX - static::LON_MIN) * static::SCALE);
    $height = (int) ((static::LAT_MAX - static::LAT_MIN) * static::SCALE);
    if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
      return FALSE;
    }
    $i = (int) $y * $width + (int) $x;
    return isset($this->landMask[$i >> 3]) && (ord($this->landMask[$i >> 3]) & (0x80 >> ($i & 7)));
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
      // Handles are capped at a third of this leg's length, so a short leg
      // next to a long one doesn't overshoot into a loop; at a hairpin turn
      // there is no handle, so the route turns at the port rather than
      // swinging on past it.
      $max = hypot($p2[0] - $p1[0], $p2[1] - $p1[1]) / 3;
      $h1 = $this->hairpin($p0, $p1, $p2) ? [0, 0] : $this->capLength([($p2[0] - $p0[0]) / 6, ($p2[1] - $p0[1]) / 6], $max);
      $h2 = $this->hairpin($p1, $p2, $p3) ? [0, 0] : $this->capLength([($p3[0] - $p1[0]) / 6, ($p3[1] - $p1[1]) / 6], $max);
      $segment = [
        $p1,
        [$p1[0] + $h1[0], $p1[1] + $h1[1]],
        [$p2[0] - $h2[0], $p2[1] - $h2[1]],
        $p2,
      ];
      // Routing keeps the straight line between points at sea; if smoothing
      // swings the curve onto land, draw this stretch straight instead.
      if ($this->curveOnLand($segment)) {
        $segment = [$p1, $p1, $p2, $p2];
      }
      $segments[] = $segment;
    }
    return $segments;
  }

  /**
   * Whether a Bezier segment (map units) runs over land away from its ends.
   */
  protected function curveOnLand(array $segment): bool {
    [$p0, $c1, $c2, $p3] = $segment;
    if ($c1 === $p0 && $c2 === $p3) {
      return FALSE;
    }
    $clearance = static::PORT_CLEARANCE * static::SCALE;
    $n = max(2, (int) ceil(hypot($p3[0] - $p0[0], $p3[1] - $p0[1])));
    for ($k = 1; $k < $n; $k++) {
      $t = $k / $n;
      $u = 1 - $t;
      $x = $u ** 3 * $p0[0] + 3 * $u ** 2 * $t * $c1[0] + 3 * $u * $t ** 2 * $c2[0] + $t ** 3 * $p3[0];
      $y = $u ** 3 * $p0[1] + 3 * $u ** 2 * $t * $c1[1] + 3 * $u * $t ** 2 * $c2[1] + $t ** 3 * $p3[1];
      if (hypot($x - $p0[0], $y - $p0[1]) < $clearance || hypot($x - $p3[0], $y - $p3[1]) < $clearance) {
        continue;
      }
      if ($this->isLand(...$this->unproject($x, $y))) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Shortest route at sea between two points, for comparing drawn routes
   * against (see VoyageMapAuditCommands).
   *
   * @return array
   *   [lon, lat] points from $a to $b inclusive.
   */
  public function shortestSeaRoute(array $a, array $b): array {
    return array_merge([$a], $this->routeAtSea($a, $b), [$b]);
  }

  /**
   * Whether the route turns back on itself at $b (by more than 120 degrees).
   */
  protected function hairpin(array $a, array $b, array $c): bool {
    $u = [$b[0] - $a[0], $b[1] - $a[1]];
    $v = [$c[0] - $b[0], $c[1] - $b[1]];
    $lengths = hypot(...$u) * hypot(...$v);
    return $lengths > 0 && ($u[0] * $v[0] + $u[1] * $v[1]) < -0.5 * $lengths;
  }

  /**
   * Scales a vector down to at most the given length.
   */
  protected function capLength(array $v, float $max): array {
    $len = hypot($v[0], $v[1]);
    return $len > $max && $len > 0 ? [$v[0] * $max / $len, $v[1] * $max / $len] : $v;
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
   * Points sampled along the smoothed path, for fitting the view to it.
   */
  protected function curvePoints(array $points): array {
    $samples = [];
    foreach ($this->segments($points) as [$p0, $c1, $c2, $p3]) {
      foreach ([0, 0.25, 0.5, 0.75, 1] as $t) {
        $u = 1 - $t;
        $samples[] = [
          $u ** 3 * $p0[0] + 3 * $u ** 2 * $t * $c1[0] + 3 * $u * $t ** 2 * $c2[0] + $t ** 3 * $p3[0],
          $u ** 3 * $p0[1] + 3 * $u ** 2 * $t * $c1[1] + 3 * $u * $t ** 2 * $c2[1] + $t ** 3 * $p3[1],
        ];
      }
    }
    return $samples;
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
