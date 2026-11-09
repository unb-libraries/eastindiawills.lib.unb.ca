<?php

namespace Drupal\eiw_core\EventSubscriber;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Limits anonymous visitors to the landing page; everything else needs login.
 *
 * Anonymous users keep core's 'access content' permission (the landing page
 * is a node), so this does the gatekeeping instead: any other page sends
 * them to SAML login (samlauth), and back to that page afterwards. Routes a
 * visitor needs in order to log in at all - SAML, one-time login links
 * (drush uli), and CSS/JS/image asset generation - stay open, as does the
 * /health probe (health_check.content).
 *
 * The landing page is recognized by its fixed UUID rather than as "the
 * front page": the front page setting is its /home alias (its node ID
 * differs per environment), and core's front page check compares internal
 * /node/N paths, so it wouldn't match.
 */
class AnonymousAccessSubscriber implements EventSubscriberInterface {

  /**
   * Routes anonymous visitors may use, besides the landing page.
   */
  const OPEN_ROUTES = [
    'user.login',
    'saml_features.login',
    'user.reset',
    'user.reset.form',
    'user.reset.login',
    'system.css_asset',
    'system.js_asset',
    'image.style_public',
    'system.403',
    'system.404',
    'health_check.content',
  ];

  public function __construct(
    protected AccountInterface $account,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // After routing (RouterListener, 32), so the route is known.
    return [KernelEvents::REQUEST => ['onRequest', 30]];
  }

  /**
   * Redirects anonymous visitors to SAML login, except on open routes.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->account->isAuthenticated()) {
      return;
    }
    $route = (string) $this->routeMatch->getRouteName();
    if (
      in_array($route, static::OPEN_ROUTES, TRUE)
      || str_starts_with($route, 'samlauth.')
      || $this->isLandingPage()
    ) {
      return;
    }
    $url = Url::fromRoute('samlauth.saml_controller_login', [], [
      'query' => ['destination' => $event->getRequest()->getRequestUri()],
    ]);
    $event->setResponse(new TrustedRedirectResponse($url->toString()));
  }

  /**
   * Whether the current route is the landing page node.
   */
  protected function isLandingPage(): bool {
    $node = $this->routeMatch->getParameter('node');
    return $this->routeMatch->getRouteName() === 'entity.node.canonical'
      && $node instanceof NodeInterface
      && $node->uuid() === \EIW_CORE_LANDING_PAGE_UUID;
  }

}
