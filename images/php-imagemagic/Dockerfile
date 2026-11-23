# Usamos Bookworm (Debian 12) que tiene repositorios más recientes y estables
ARG RUNTIME_STAGE=sparrowfy-dependencies
FROM php:8.2-apache-bookworm AS sparrowfy-dependencies

ARG DEBIAN_FRONTEND=noninteractive

# Instalar dependencias esenciales, librerías de imágenes (GD y Magick), libgmp (WebPush) y Docker CLI
RUN apt-get update \
    && apt-get install -y \
        ca-certificates \
        gnupg \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        libzip-dev \
        zlib1g-dev \
        libonig-dev \
        libxml2-dev \
        libcurl4-openssl-dev \
        libssl-dev \
        libmagickwand-dev \
        imagemagick \
        libgmp-dev \
        curl \
        wget \
        git \
        mariadb-client \
        p7zip-full \
        # unrar \
        unrar-free \
        unzip \
        tar \
        gzip \
        xz-utils \
        bzip2 \
        sysstat \
        ffmpeg \
        webp \
        libjpeg-turbo-progs \
        pngquant \
        optipng \
    && mkdir -p /etc/apt/keyrings \
    && curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable" > /etc/apt/sources.list.d/docker.list \
    && apt-get update \
    && apt-get install -y docker-ce-cli \
    && rm -rf /var/lib/apt/lists/*

# ============================================================================
# INICIO PDF2KINDLE - HERRAMIENTAS DE CONVERSION DE LIBROS
# Para desactivar el proyecto en un futuro, comenta o elimina este RUN completo.
# No modifica el bloque de paquetes original ni otras herramientas existentes.
# Paquetes de Debian Bookworm aptos para arm64/armhf.
# - python3-fitz: PyMuPDF, lectura de texto, coordenadas e imagenes del PDF
# - python3-ebooklib: creacion de EPUB con capitulos, portada e indice
# - python3-pil: generacion y tratamiento de portadas
# - python3-lxml: procesamiento de XHTML/XML
# - calibre: herramienta ebook-convert para pasar EPUB a AZW3
# - poppler-utils: utilidades pdfinfo/pdftotext para diagnostico
# - fonts-dejavu-core: tipografias disponibles para generar portadas
# Importante: instalar paquetes Python con apt permite usar /usr/bin/python3.
# ============================================================================
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        python3-fitz \
        python3-ebooklib \
        python3-pil \
        python3-lxml \
        calibre \
        poppler-utils \
        fonts-dejavu-core \
        libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*
# FIN PDF2KINDLE - HERRAMIENTAS DE CONVERSION DE LIBROS

# Configurar GD con soporte para FreeType, JPEG y WebP antes de compilar
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

# Instalar extensiones PHP nativas
RUN docker-php-ext-install -j$(nproc) \
    mysqli \
    pdo_mysql \
    mbstring \
    zip \
    gd \
    bcmath \
    exif \
    xml \
    curl \
    gmp \
    opcache

# Binario oficial por arquitectura de destino.
ARG TARGETARCH
RUN set -eux; \
    apt-get update; apt-get install -y --no-install-recommends ca-certificates wget unzip; \
    case "${TARGETARCH:-$(dpkg --print-architecture)}" in arm64) asset=yt-dlp_linux_aarch64 ;; amd64) asset=yt-dlp_linux ;; *) exit 1 ;; esac; \
    wget -O /usr/local/bin/yt-dlp "https://github.com/yt-dlp/yt-dlp/releases/latest/download/$asset"; \
    chmod 0755 /usr/local/bin/yt-dlp; /usr/local/bin/yt-dlp --version; rm -rf /var/lib/apt/lists/*

# ============================================================================
# INICIO PDF2KINDLE - BASE DE DATOS LOCAL
# Desactivar: comenta o elimina esta unica instruccion RUN.
# Agrega SQLite/PDO SQLite a PHP para el catalogo y la cola de conversiones.
# Si prefieres MariaDB (ya tienes pdo_mysql), este bloque es opcional.
# ============================================================================
# RUN docker-php-ext-install pdo_sqlite sqlite3
# FIN PDF2KINDLE - BASE DE DATOS LOCAL

# Compilar e instalar la extensión Imagick mediante PECL
RUN pecl install imagick \
    && docker-php-ext-enable imagick

# Instalar Composer directamente desde la imagen oficial
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Crear grupo docker coincidiendo con el GID 124 de la Raspberry Pi - solo el sitio fredy lo usa
#RUN groupadd -g 124 docker || true \
#    && usermod -aG docker www-data

# Habilitar mod_rewrite y headers de Apache
RUN a2enmod rewrite headers

# Configurar DocumentRoot a /var/www/html
ENV APACHE_DOCUMENT_ROOT /var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Crear el directorio y dar permisos al usuario www-data
RUN mkdir -p /var/www/html \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Directorio de sesiones privadas de Sparrow-fy
RUN mkdir -p /var/lib/hlsml-sessions \
    && chown www-data:www-data /var/lib/hlsml-sessions \
    && chmod 700 /var/lib/hlsml-sessions

# Apache ejecutado sin privilegios de root
RUN sed -ri \
    -e 's/^User .*/User www-data/' \
    -e 's/^Group .*/Group www-data/' \
    /etc/apache2/apache2.conf

# Directorios de ejecución para Apache sin privilegios
RUN install -d -o www-data -g www-data -m 0755 /var/run/apache2 \
    && install -d -o www-data -g www-data -m 0755 /var/lock/apache2 \
    && install -d -o www-data -g www-data -m 0755 /var/log/apache2

# NumPy para análisis de audio (correlación/FFT) sin pip ni venv.
# Se instala como paquete Debian para usar /usr/bin/python3 del contenedor.
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3-numpy \
    && rm -rf /var/lib/apt/lists/*

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost:8080/ || exit 1

CMD ["apache2-foreground"]

FROM ${RUNTIME_STAGE}
ENV SPARROWFY_HOST=localhost
WORKDIR /var/www/html/audio
COPY --chown=www-data:www-data app/public/ ./
COPY --chown=www-data:www-data app/src/ ./
COPY --chown=www-data:www-data app/config/settings.php ./settings.php
COPY --chown=www-data:www-data app/scripts/ ./scripts/
COPY app/config/schema.sql images/php-imagemagic/init_db.py images/php-imagemagic/backup.py /opt/sparrowfy/
COPY images/php-imagemagic/site.conf /etc/apache2/sites-available/000-default.conf
COPY images/php-imagemagic/www.policy.xml /etc/ImageMagick-6/policy.xml
COPY images/php-imagemagic/php-hardening.ini /usr/local/etc/php/conf.d/98-hardening.ini
COPY images/php-imagemagic/php-session.ini /usr/local/etc/php/conf.d/99-session-security.ini
COPY images/php-imagemagic/start.sh images/php-imagemagic/health.sh images/php-imagemagic/processing.sh /opt/sparrowfy/
RUN install -m 0755 /opt/sparrowfy/start.sh /usr/local/bin/sparrowfy-start && install -m 0755 /opt/sparrowfy/health.sh /usr/local/bin/sparrowfy-health && install -m 0755 /opt/sparrowfy/processing.sh /usr/local/bin/sparrowfy-processing
RUN set -eux; : > /etc/apache2/ports.conf; a2enmod rewrite headers;     install -d -o www-data -g www-data -m 0770 data backups /sparrowfy /sparrowfy/database /sparrowfy/hls_out /sparrowfy/inbox;     install -d -o www-data -g www-data -m 0700 /var/lib/hlsml-sessions;     ln -s /sparrowfy/hls_out hls_out; chmod -R a+rx scripts;     php -r 'foreach (["pdo_sqlite","sqlite3","imagick","gd"] as $e) if (!extension_loaded($e)) exit(1);';     apache2ctl -t
USER www-data
EXPOSE 8080
HEALTHCHECK CMD /usr/local/bin/sparrowfy-health
CMD ["/usr/local/bin/sparrowfy-start"]
