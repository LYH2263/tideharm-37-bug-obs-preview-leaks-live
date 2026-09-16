FROM php:8.3-cli
RUN apt-get update \
  && apt-get install -y --no-install-recommends libsqlite3-dev \
  && docker-php-ext-install pdo pdo_sqlite \
  && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . .
ENV DATA_DIR=/data
EXPOSE 8800
CMD ["php", "-S", "0.0.0.0:8800", "-t", "public", "public/index.php"]
