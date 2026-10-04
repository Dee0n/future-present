#!/bin/bash
# Lint ext/tes_world and copy it to the live server (nothing is copied if any file fails php -l).
set -e
SRC=/home/dwemer/TES-Speech-Adapter/ext/tes_world
DST=/var/www/html/HerikaServer/ext/tes_world
for f in "$SRC"/*.php; do
    php -l "$f" > /dev/null || { echo "LINT FAILED: $f"; exit 1; }
done
cp "$SRC"/* "$DST"/
chown dwemer:www-data "$DST"/*
chmod 664 "$DST"/*.php
echo "deployed: $(ls "$DST" | wc -l) files"
