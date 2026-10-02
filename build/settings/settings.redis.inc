<?php

/**
 * @file
 * Redis configuration settings.
 */

if (!empty($settings['chq_redis_cache_enabled'])) {
  $settings['cache']['default'] = 'cache.backend.redis';
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host'] = 'drupal-redis-lib-unb-ca';
  $settings['redis.connection']['port'] = '6379';
}
