<?php

namespace Drupal\eiw_migrate\Plugin\migrate\source;

use Drupal\migrate_source_csv\Plugin\migrate\source\CSV;
use Drupal\migrate\Row;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

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
            
            if ($name) {
              $row->setSourceProperty(
                'ship',
                $this->getOrCreateNodeId('title', $name, 'eiw_ship') 
              );
            }
          }
          else {
            $label = ucwords($this->snakeToSentence($label));
            $add_names[] = "$label: $name";
          }
        }
        // Update additional names.
        $row->setSourceProperty('add_names', $add_names);
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
    // Update courts.
    $court = $row->getSourceProperty('tag');
    echo "\nREACHED\n";
    dump($court);
    
    if ($court) {
      $row->setSourceProperty(
        'court_ref',
        $this->getOrCreateTermId($court, 'eiw_courts')
      );
    }
  }

  /**
   * Get the ID of a node by field value and bundle.
   * If the node does not exist, create it with the specified field populated.
   *
   * @param string $field_name The machine name of the field (e.g., 'field_title').
   * @param mixed $field_value The value to search for and populate in the field.
   * @param string $bundle The content type machine name.
   * @return int|null The node ID (nid) or null on failure.
   */
  public function getOrCreateNodeId(string $field_name, $field_value, string $bundle): ?int {
    // Try to find the node by field value and bundle
    $nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        trim($field_name) => trim($field_value),
        'type' => $bundle,
      ]);

    if (!empty($nodes)) {
      // Node exists - return its ID
      $node = reset($nodes);
      return $node->id();
    }

    // Node does not exist - create it with only the specified field populated
    $new_node = Node::create([
      'type' => $bundle,
      $field_name => $field_value,
    ]);
    $new_node->save();

    return $new_node->id();
  }

    /**
   * Get the ID of a taxonomy term by name and vocabulary.
   * If the term does not exist, create it and return its ID.
   *
   * @param string $name The name of the taxonomy term.
   * @param string $vocabulary The vocabulary machine name.
   * @return int|null The term ID (tid) or null on failure.
   */
  public function getOrCreateTermId(string $name, string $vocabulary): ?int {
    // Try to find the term by name and vocabulary
    $term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'name' => trim($name),
        'vid' => $vocabulary,
      ]);

    if (!empty($term)) {
      // Term exists - return its ID
      $term = reset($term);
      return $term->id();
    }

    // Term does not exist - create it
    $new_term = Term::create([
      'name' => $name,
      'vid' => $vocabulary,
    ]);
    $new_term->save();

    return $new_term->id();
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

  /**
   * Convert snake_case string to Sentence case.
   *
   * @param string $snake
   *   The input string in snake_case format.
   * @return string
   *   The string converted to sentence case.
   */
  public function snakeToSentence($snake) {
      // Replace underscores with spaces
      $sentence = str_replace('_', ' ', $snake);
      // Lowercase everything
      $sentence = strtolower($sentence);
      // Capitalize first letter
      $sentence = ucfirst($sentence);
      return $sentence;
  }

}