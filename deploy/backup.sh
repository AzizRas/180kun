#!/bin/sh
# LEVEL 180 — ночная копия: база (горячая копия SQLite) и папка фото.
# Хранит 14 последних копий. Копии остаются на этом же сервере в Узбекистане;
# если копировать их куда-то ещё — тоже только внутри страны (фото, § 11).
set -eu

DATA_DIR="${DATA_DIR:-/var/lib/level180}"
DEST="${BACKUP_DIR:-/var/backups/level180}"
STAMP="$(date -u +%Y%m%d-%H%M)"
mkdir -p "$DEST"

# .backup делает согласованную копию даже во время записи (WAL).
sqlite3 "$DATA_DIR/db/level180.sqlite" ".backup '$DEST/db-$STAMP.sqlite'"
tar -czf "$DEST/files-$STAMP.tar.gz" -C "$DATA_DIR" secret.key media 2>/dev/null || true
chmod 600 "$DEST/db-$STAMP.sqlite" "$DEST/files-$STAMP.tar.gz"

ls -1t "$DEST"/db-*.sqlite 2>/dev/null | tail -n +15 | xargs -r rm -f
ls -1t "$DEST"/files-*.tar.gz 2>/dev/null | tail -n +15 | xargs -r rm -f
echo "$STAMP ok"
