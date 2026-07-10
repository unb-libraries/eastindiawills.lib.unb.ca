FROM ghcr.io/unb-libraries/drupal:11.x-1.x-unblib

# Install additional OS packages.
ENV ADDITIONAL_OS_PACKAGES="postfix php${PHP_VERSION}-ldap php${PHP_VERSION}-pecl-redis php${PHP_VERSION}-xmlreader"

ENV DRUPAL_SITE_ID="wills"
ENV DRUPAL_SITE_URI="eastindiawills.lib.unb.ca"
ENV DRUPAL_SITE_UUID="e21a0a05-37d7-47d0-9a1c-a0b00bdf6fef"

# Build application.
COPY ./build/ /build/
RUN ${RSYNC_MOVE} /build/scripts/container/ /scripts/ && \
  /scripts/addOsPackages.sh && \
  /scripts/initOpenLdap.sh && \
  /scripts/setupStandardConf.sh && \
  /scripts/build.sh

# Deploy configuration.
COPY ./configuration ${DRUPAL_CONFIGURATION_DIR}
RUN /scripts/pre-init.d/72_secure_config_sync_dir.sh

# Deploy custom modules, themes.
COPY ./custom/themes ${DRUPAL_ROOT}/themes/custom
COPY ./custom/modules ${DRUPAL_ROOT}/modules/custom

# Container metadata.
ARG BUILD_DATE
ARG VCS_REF
ARG VERSION
LABEL ca.unb.lib.generator="drupal11" \
  org.opencontainers.image.title="eastindiawills.lib.unb.ca" \
  org.opencontainers.image.description="eastindiawills.lib.unb.ca provides a history of IT services at UNB." \
  org.opencontainers.image.vendor="University of New Brunswick Libraries" \
  org.opencontainers.image.authors="UNB Libraries <libsupport@unb.ca>" \
  org.opencontainers.image.url="https://eastindiawills.lib.unb.ca" \
  org.opencontainers.image.source="https://github.com/unb-libraries/eastindiawills.lib.unb.ca" \
  org.opencontainers.image.version="$VERSION" \
  org.opencontainers.image.revision="$VCS_REF" \
  org.opencontainers.image.created="$BUILD_DATE"
