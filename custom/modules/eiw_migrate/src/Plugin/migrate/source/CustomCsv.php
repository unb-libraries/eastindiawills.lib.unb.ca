<?php

namespace Drupal\eiw_migrate\Plugin\migrate\source;

use Drupal\migrate_source_csv\Plugin\migrate\source\CSV;
use Drupal\migrate\Row;

/**
 * Custom CSV source plugin to allow row skipping.
 *
 * @MigrateSource(
 *   id = "custom_csv"
 * )
 */
class CustomCsv extends CSV {

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $this->process_tropy($row);
    // Skip row if last_name is missing or empty.
    if (!$row->getSourceProperty('last_name')) {
      return FALSE;
    }
    // Otherwise, process as normal.
    return parent::prepareRow($row);
  }

  /**
   * Process Tropy migration row.
   */
  public function process_tropy(&$row) {
    // Parse notes.
    $notes_raw = $row->getSourceProperty('note');
    $first = '';
    $last = '';

    if ($notes_raw) {
      $tokens = explode('---', $notes_raw);
      $notes = [];
      
      foreach ($tokens as $token) {
        // Raw label: Everything before colon, trimmed.
        $label = $this->text_trim(strstr($token, ':', TRUE), FALSE);
        // Raw value: The rest, trimmed. 
        $value = $this->text_trim(str_replace($label, '', $token), FALSE);
        // Each value pair will be inthe form: 'a_label' => 'The value'.
        $notes[preg_replace('/\s+/', '_', strtolower($label))] = $value;
      }

      if (!empty($notes)) {
        // Process testator.
        $testator = $notes['testator'] ?? NULL;
        // Parse testator.
        $tokens = array_reverse(explode(' ', $testator));
        $last = $tokens[0];
        unset($tokens[0]);
        $tokens = array_reverse($tokens);
        $first = implode(' ', $tokens);
        // Update reference.
        $reference = $notes['reference'] ?? NULL;
        $row->setSourceProperty('reference', $reference);
        // Update date of will.
        $date = $notes['date_of_will'] ?? NULL;
        $row->setSourceProperty('date_of_will', $date);
        // Update date of probate.
        $probate = $notes['date_of_probate'] ?? NULL;
        $row->setSourceProperty('date_of_probate', $probate);
        // Process ship and additional names.
        $add_names = [];
        // Filter notes containing 'name' in the key.
        $names = array_filter(
          $notes,
          function($label) {
            return strpos($label, 'name') !== false;
          },
          ARRAY_FILTER_USE_KEY
        );
        // Iterate and populate additional names.
        foreach($names as $label => $name) {
          // Update ship name when available.
          if (strpos($label, 'ship_name')) {
            $row->setSourceProperty('ship_name', $name);
          }
          else {
            $add_names[] = "$label $name";
          }
        }
        // Update additional names.
        $row->setSourceProperty('additional_names', $add_names);
      }
    }
    // If no last name, retrieve from Tropy title.
    if (!$last) {
      $tropy_title = $row->getSourceProperty('tropy_title');
      $parts = explode(',', $tropy_title);
      
      if ($parts[0] == $tropy_title) {
        $parts = explode('-', $tropy_title);
        $last = $parts[0] ?? NULL;
        $first = $parts[1] ?? NULL;
      }
      else {
        $last = trim($parts[0]);
        $first = trim($parts[1]);
      }
    }
    // Update names.
    $row->setSourceProperty('first_name', $first);
    $row->setSourceProperty('last_name', $last);
  }

  /**
   * Trim spaces and special characters from text.
   *
   * @param string $text
   *   The text to process.
   * @param bool $sentence
   *   Is the text to be treated as a sentence?
   * @param string $starters
   *   Starter special characters to ignore for sentences.
   * @param string $enders
   *   Ender special characters to ignore for sentences.
   */
  public function text_trim(
    string $text, 
    bool $sentence = FALSE, 
    array $starters = ["'", '"', '(', '['], 
    array $enders = ['.', '!', '?' , "'", '"', ')', ']']) {
    $first = substr($text, 0, 1);
    $last = substr($text, -1);
    $starters = !$sentence ? [] : $starters;
    $enders = !$sentence ? [] : $enders;
  
    while ($first and !ctype_alnum($first) and !in_array($first, $starters)) {
      $text = substr($text, 1);
      $first = substr($text, 0, 1);
    }
    
    while ($first and !ctype_alnum($last) and !in_array($last, $enders)) {
      $text = substr($text, 0, -1);
      $last = substr($text, -1);
    }
  
  return $text;
  }
}