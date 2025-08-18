<?php

namespace Drupal\eiw_migrate\Plugin\migrate\source;

use Drupal\Core\File\FileExists;
use Drupal\file\Entity\File;
use Drupal\migrate_source_csv\Plugin\migrate\source\CSV;
use Drupal\migrate\Row;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
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
    $this->processTropy($row);
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
  public function processTropy(&$row) {
    // Parse notes.
    $notes_raw = $row->getSourceProperty('note');
    $notes_raw = $notes_raw == '' ? $row->getSourceProperty('note2') : $notes_raw;
    $notes_raw = $notes_raw == '' ? $row->getSourceProperty('note3') : $notes_raw;
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
        // Each value pair will be in the form: 'a_label' => 'The value'.
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
          $label = ucwords($this->snakeToSentence($label));
          $add_names[] = "$label: $name";
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

    // Process tags.
    $tags = $row->getSourceProperty('tags');
    
    if ($tags) {
      $tokens = explode(', ', $tags);

      foreach($tokens as $tag) {        
        // Map, collect, and unset court values.
        if (strtolower($tag) == 'a') {
          $court = 'Archdeaconry Court of London';
        }
        elseif (strtolower($tag) == 'c') {
          $court = 'Commissary Court of London';
        }
        elseif (in_array(strtolower($tag), ['p', 'pcc'])) {
          $court = 'Prerogative Court of Canterbury';
        }
        else {
          $court = "Other";
          // Search for tag in roles and marital status.
          $role_tids = $this->termByName($tag, 'eiw_roles');
          $marital_tids = $this->termByName($tag, 'eiw_marital_statuses');

          if (!empty($role_tids)) {
            // Update role.
            $role_tid = reset($role_tids);
          }
          elseif (!empty($marital_tids)) {
            // Update marital status.
            $marital_tid = reset($marital_tids);
            $row->setSourceProperty('marital_ref', $marital_tid);
          }
          else {
            $ship_nid = $this->getOrCreateNodeId('title', $tag, 'eiw_ship');
          }
        }        
        // Update court.
        if (isset($court) and $court) {
          $court_tid = $this->termByName($court, 'eiw_courts');
          
          if ($court_tid) {
            $row->setSourceProperty(
              'court_ref',
              $court_tid
            );
          }
        }
      }
      // Will voyage.
      if ((isset($ship_nid)) or
        (isset($role_tid))) {
        // Create paragraph.
        $will_voyage = Paragraph::create(['type' => 'eiw_will_voyage']);
        if (isset($ship_nid) && $ship_nid) {
          $will_voyage->set('field_ship', ['target_id' => $ship_nid]);
        }
        if (isset($role_tid) && $role_tid) {
          $will_voyage->set('field_role', ['target_id' => $role_tid]);
        }
        // Save the paragraph and get ID.
        $will_voyage->save();
        $pid = $will_voyage->id();
        // Update will voyage.
        $row->setSourceProperty(
          'will_voyage_ref',
          $pid
        );        
      }
    }
    
    // Process images (PDF).
    $path = $row->getSourceProperty('path');
    $filename = $path ? basename($row->getSourceProperty('path')) : NULL;
    $file = $filename ? "/app/html/sites/default/files/eiw_migrate_pdf/$filename" : NULL;
    $fid = $file ? [$this->fileFromUrl($file)] : NULL;

    $row->setSourceProperty(
      'img_ref',
      $fid
    );
  }

  /**
   * Get the ID of a node by field value and bundle (case-insensitive).
   * If the node does not exist, create it with the specified field populated.
   *
   * @param string $field_name The machine name of the field (e.g., 'field_title').
   * @param mixed $field_value The value to search for and populate in the field.
   * @param string $bundle The content type machine name.
   * @return int|null The node ID (nid) or null on failure.
   */
  public function getOrCreateNodeId(string $field_name, $field_value, string $bundle): ?int {
    // Query all nodes of the given bundle where field is set, ignoring access.
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', $bundle);
    $nids = $query->execute();

    if ($nids) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $node_value = $node->get($field_name)->value ?? '';
        // Case-insensitive comparison
        if (strcasecmp((string)$node_value, (string)$field_value) === 0) {
          return $node->id();
        }
      }
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
   * Get the ID of a taxonomy term by name and vocabulary (case-insensitive).
   * If the term does not exist, create it and return its ID.
   *
   * @param string $name The name of the taxonomy term.
   * @param string $vocabulary The vocabulary machine name.
   * @return int|null The term ID (tid) or null on failure.
   */
  public function getOrCreateTermId(string $name, string $vocabulary): ?int {
    // Query all terms in the vocabulary, ignoring access.
    $query = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary);
    $tids = $query->execute();

    if ($tids) {
      $terms = Term::loadMultiple($tids);
      foreach ($terms as $term) {
        $term_name = $term->getName();
        // Case-insensitive comparison
        if (strcasecmp($term_name, $name) === 0) {
          return $term->id();
        }
      }
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
   * Creates a Drupal file entity from a file URL and returns the target_id.
   *
   * @param string $url
   *   The absolute URL to the file.
   *
   * @param array $file_options
   *   (optional) Options for file_save_data, e.g., ['uri' => 'public://filename.ext'].
   *
   * @return int|false
   *   The file entity ID (target_id) on success, or FALSE on failure.
   */
  public function fileFromUrl(string $url, array $file_options = []) {
    // Download the file data.
    $data = file_get_contents($url);
    if ($data === FALSE) {
      \Drupal::logger('eiw_migrate')->error('Could not fetch file from URL: @url', ['@url' => $url]);
      return FALSE;
    }

    // Determine the file name.
    $basename = basename(parse_url($url, PHP_URL_PATH));
    if (empty($basename)) {
      $basename = 'downloaded_file_' . time();
    }

    // Determine the file destination.
    $destination = $file_options['uri'] ?? 'public://' . $basename;

    // Save the file in Drupal's managed files.
    $file_repository = \Drupal::service('file.repository');
    $file = $file_repository->writeData($data, $destination, FileExists::Replace);

    if (!$file) {
      \Drupal::logger('eiw_migrate')->error('Could not save file from URL: @url', ['@url' => $url]);
      return FALSE;
    }

    // Set status to permanent.
    if (isset($file_options['filename'])) {
      $file->setFilename($file_options['filename']);
    }
    $file->setPermanent();
    $file->save();

    return $file->id();
  }

  /**
   * Finds node IDs in the given bundle where the lowercase title matches $value.
   *
   * @param string $value
   *   String to match against node titles (case-insensitive).
   * @param string $bundle
   *   The node bundle (content type).
   * @return array
   *   Array of matching node IDs.
   */
  public function nodeByTitle($value, $bundle) {
    $query = \Drupal::entityQuery('node')
      ->accessCheck(FALSE)
      ->condition('type', $bundle);

    $nids = $query->execute();
    $matched_nids = [];

    if (!empty($nids)) {
      $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $title = $node->getTitle();
        if (strtolower($title) === strtolower($value)) {
          $matched_nids[] = $node->id();
        }
      }
    }

    return $matched_nids;
  }

  /**
   * Finds taxonomy term IDs in the given vocabulary where the lowercase term name matches $value.
   *
   * @param string $value
   *   String to match against term names (case-insensitive).
   * @param string $vocabulary
   *   The vocabulary machine name.
   * @return array
   *   Array of matching term IDs.
   */
  public function termByName($value, $vocabulary) {
    $query = \Drupal::entityQuery('taxonomy_term')
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary);

    $tids = $query->execute();
    $matched_tids = [];

    if (!empty($tids)) {
      $terms = \Drupal\taxonomy\Entity\Term::loadMultiple($tids);
      foreach ($terms as $term) {
        $name = $term->getName();
        if (strtolower($name) === strtolower($value)) {
          $matched_tids[] = $term->id();
        }
      }
    }

    return $matched_tids;
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

  /**
   * Dump item to terminal
   *
   * @param string $label
   *   A label for identification.
   * @param mixed $item
   *   The item to dump.
   */
  public function tdump($label, $item) {
    $label = strtoupper($label);
    echo "\n";
    echo "***$label***\n";
    echo var_dump($item);
    echo "***$label***";
    echo "\n\n";
  }

}