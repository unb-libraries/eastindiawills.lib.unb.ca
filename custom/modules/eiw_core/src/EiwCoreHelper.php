<?php

namespace Drupal\eiw_core;

use Drupal\node\NodeInterface;

/**
 * Static helper methods for the eiw_core module.
 */
class EiwCoreHelper {

  /**
   * Converts a string to a "slug" format.
   *
   * Replaces all non-alphanumeric characters with dashes, collapses
   * consecutive dashes, and trims leading/trailing dashes. Suitable for
   * URLs or filenames.
   *
   * @param string $string
   *   The input string to be slugified.
   *
   * @return string
   *   The slugified version of the string.
   */
  public static function eiwCoreSlugify(string $string): string {
    $string = preg_replace('/[^A-Za-z0-9]+/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
  }

  /**
   * Builds a will's "Last, First [ date ]" display title.
   *
   * The node's own title (see eiw_core_node_presave()) is just "Last,
   * First" - it drives the URL alias, admin listings, breadcrumbs, etc.,
   * none of which need the probate date. This builds the fuller, dated
   * version for the two places that still show it: the will's own page
   * title and the citation line. A year-only probate date
   * (field_year_only_probate) shows as just the year, matching how the
   * date field itself renders elsewhere (see eiw_core_preprocess_node()).
   *
   * @param \Drupal\node\NodeInterface $node
   *   An eiw_will node.
   *
   * @return string
   *   The display title.
   */
  public static function eiwWillDisplayTitle(NodeInterface $node): string {
    $last_name = !$node->get('field_last_name')->isEmpty() ? trim($node->get('field_last_name')->value) : 'N/A';
    $first_name = !$node->get('field_first_name')->isEmpty() ? ', ' . trim($node->get('field_first_name')->value) : '';
    $name = $last_name . $first_name;

    if ($node->get('field_date_of_probate')->isEmpty()) {
      return $name . ' [ n.d. ]';
    }
    $date = $node->get('field_date_of_probate')->value;
    if (!$node->get('field_year_only_probate')->isEmpty() && $node->get('field_year_only_probate')->value) {
      $date = substr($date, 0, 4);
    }
    return $name . ' [ ' . $date . ' ]';
  }

}
