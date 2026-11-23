FROM ghcr.io/mornyeapp/mornye-python@sha256:31953d26543dfe2d5b1a3aa015cf9e02b1aa1c465d5f910a978f2e0f0e6e06a9
USER root
RUN install -d -o 33 -g 33 -m 0700 /config /config/.cache /config/home \
    && install -d -o 33 -g 33 -m 0770 /inbox
COPY --chown=33:33 --chmod=0600 preferences.json /config/.cache/gui-settings.json
ENV HOME=/config/home MORNYE_DATA_DIR=/config MORNYE_CACHE_DIR=/config/.cache MORNYE_DOWNLOAD_DIR=/inbox
USER 33:33
