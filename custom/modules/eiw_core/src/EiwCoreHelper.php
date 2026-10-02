<?php

namespace Drupal\eiw_core;

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

}
