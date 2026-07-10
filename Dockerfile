FROM php:8.5-cli-trixie

# pulseaudio-utils: paplay/pactl (audio via the host's PipeWire-Pulse socket)
# libnotify-bin:    notify-send
# libglib2.0-bin:   gdbus (to close the notification when the alarm is dismissed)
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        $PHPIZE_DEPS \
        pulseaudio-utils \
        libnotify-bin \
        libglib2.0-bin \
    && docker-php-ext-install pcntl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /repo
CMD ["php", "app/timer.php"]
