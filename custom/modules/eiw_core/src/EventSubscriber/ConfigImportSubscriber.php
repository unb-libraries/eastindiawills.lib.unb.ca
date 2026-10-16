<?php

namespace Drupal\eiw_core\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Creates the landing page node once the "page" bundle it needs is ready.
 *
 * hook_install() handles a brand-new site install, but this project's
 * deploy pipeline runs hook_update() hooks *before* configuration import
 * (deploy.d/10 vs. deploy.d/40), so a hook_update() can't rely on the "page"
 * bundle - ordinary site-wide config, not this module's own - already
 * existing either. ConfigEvents::IMPORT instead fires once a config import
 * has actually finished, so by then it reliably does.
 */
class ConfigImportSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::IMPORT => 'onConfigImport',
    ];
  }

  /**
   * Reacts to a completed config import.
   */
  public function onConfigImport(): void {
    eiw_core_create_landing_page_if_missing();
  }

}
