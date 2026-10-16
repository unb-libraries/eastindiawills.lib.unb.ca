<?php

namespace Drupal\eiw_core\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Sends an already-authenticated user to the landing page, not /user/{id}.
 *
 * The site's front page is the login form itself (system.site.page.front =
 * /user/login), not a homepage - so visiting / while already logged in hits
 * that route and gets access-denied (its route requires being logged out).
 * Core's own \Drupal\user\EventSubscriber\AccessDeniedSubscriber::on403()
 * catches exactly that 403 and redirects to the user's own account page;
 * this runs at a higher priority to redirect to the landing page instead,
 * and stops propagation so core's subscriber doesn't run after it and
 * overwrite the response back to /user/{id}.
 *
 * eiw_core_form_user_login_form_alter() handles the sibling case - actually
 * submitting the login form while logged out - which never reaches this
 * exception-handling path at all.
 */
class AuthenticatedLoginRedirectSubscriber extends HttpExceptionSubscriberBase {

  public function __construct(
    protected AccountInterface $account,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function getHandledFormats(): array {
    return ['html'];
  }

  /**
   * {@inheritdoc}
   */
  protected static function getPriority(): int {
    // Higher than AccessDeniedSubscriber's 75, so this runs first.
    return 100;
  }

  /**
   * Redirects an authenticated user away from the login page.
   */
  public function on403(ExceptionEvent $event): void {
    $route_name = RouteMatch::createFromRequest($event->getRequest())->getRouteName();
    if ($route_name === 'user.login' && $this->account->isAuthenticated()) {
      $url = Url::fromUserInput('/home', ['absolute' => TRUE]);
      $event->setResponse(new RedirectResponse($url->toString()));
      $event->stopPropagation();
    }
  }

}
