<?php
/**
 * This script runs a series of shell commands to install Drupal migration modules
 * and enable the 'eiw_migrate' module, then lists available migrations.
 */

// List of shell commands to execute
$commands = [
    'composer require drupal/migrate_plus drupal/migrate_tools drupal/migrate_source_csv',
    'drush en --yes eiw_migrate',
    'drush ms'
];

foreach ($commands as $cmd) {
    echo "Running: $cmd\n";
    // Redirect stderr to stdout and capture output
    $output = [];
    $return_var = 0;
    exec($cmd . ' 2>&1', $output, $return_var);
    // Print output
    foreach ($output as $line) {
        echo $line . "\n";
    }
    // Print status
    if ($return_var === 0) {
        echo "Command succeeded.\n\n";
    } else {
        echo "Command failed with exit code $return_var.\n\n";
    }
}
