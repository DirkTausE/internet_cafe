#!/usr/bin/env bash
# Kleines Hilfs-Skript: zeigt Struktur
echo "Projektstruktur:"
tree -a -I '.git|node_modules|vendor'
