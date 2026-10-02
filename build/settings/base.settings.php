<?php

/**
 * @file
 * Include global settings overrides here.
 */

// Redis.
$settings['cache_prefix']['default'] = 'DRUPAL_SITE_ID_';
$settings['chq_redis_cache_enabled'] = TRUE;
require_once dirname(__FILE__) . "/settings.redis.inc";

// Newrelic.
if (extension_loaded('newrelic')) {
  require_once dirname(__FILE__) . "/settings.newrelic.inc";
}

$settings['config_sync_directory'] = 'DRUPAL_CONFIGURATION_DIR';

// Explicitly retain native HTML5 form validation; Drupal 12 will default
// this to FALSE. See https://www.drupal.org/node/3537128.
$settings['enable_html5_validation'] = TRUE;
