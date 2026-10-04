#!/bin/bash
# Lint ext/tes_world and ext/tes_crime and copy them to the live server (nothing is copied if any file fails php -l).
set -e
for P in tes_world tes_crime; do
    SRC=/home/dwemer/TES-Speech-Adapter/ext/$P
    for f in "$SRC"/*.php; do
        php -l "$f" > /dev/null || { echo "LINT FAILED: $f"; exit 1; }
    done
done
for P in tes_world tes_crime; do
    SRC=/home/dwemer/TES-Speech-Adapter/ext/$P
    DST=/var/www/html/HerikaServer/ext/$P
    cp "$SRC"/* "$DST"/
    chown dwemer:www-data "$DST"/*
    chmod 664 "$DST"/*.php
    echo "deployed $P: $(ls "$DST" | wc -l) files"
done
