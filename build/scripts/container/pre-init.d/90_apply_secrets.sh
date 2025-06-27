#!/usr/bin/env sh

# reCAPTCHA
drush cset recaptcha.settings site_key $HIT_RECAPTCHA_SITE_KEY
drush cset recaptcha.settings secret_key $HIT_RECAPTCHA_SECRET_KEY
