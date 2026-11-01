#!/bin/sh
# Bundled beside platform-tools. No global installation or fixed drive is required.
NO_PAUSE=0
[ "${1:-}" = '--no-pause' ] && NO_PAUSE=1
SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd) || exit 1
ADB="$SCRIPT_DIR/platform-tools/adb"
STATE_FILE=''
cleanup() { [ -z "$STATE_FILE" ] || rm -f "$STATE_FILE"; }
trap cleanup 0
trap 'exit 130' INT
trap 'exit 143' TERM HUP
finish() {
    code=$1
    if [ "$NO_PAUSE" -eq 0 ] && [ -t 0 ]; then
        printf '\nTekan Enter untuk menutup... '
        IFS= read -r answer || :
    fi
    exit "$code"
}
fail() { printf '\nGAGAL: %s\n' "$1"; finish 1; }
printf 'HansDev Forwarder - Aktivasi listener\nADB: %s\n' "$ADB"
[ -f "$ADB" ] || fail 'Ekstrak seluruh paket. Folder platform-tools harus berada di samping script ini.'
[ -x "$ADB" ] || chmod u+x "$ADB" || fail 'ADB tidak dapat dijalankan. Periksa izin file.'
STATE_FILE=$(mktemp "${TMPDIR:-/tmp}/hansdev-listener.XXXXXX") || fail 'Tidak dapat membuat file sementara.'
printf 'Hubungkan satu HP, aktifkan USB debugging dan buka kunci layarnya.\n'
while :; do
    if ! "$ADB" devices >"$STATE_FILE" 2>&1; then
        cat "$STATE_FILE"
        fail 'ADB menolak pemeriksaan perangkat.'
    fi
    total=$(awk '$2=="device" || $2=="unauthorized" || $2=="offline" {n++} END {print n+0}' "$STATE_FILE")
    [ "$total" -le 1 ] || fail 'Ada lebih dari satu perangkat. Sisakan satu HP.'
    SERIAL=$(awk '$2=="device" {print $1}' "$STATE_FILE")
    [ -z "$SERIAL" ] || break
    cat "$STATE_FILE"
    printf '\nJika unauthorized, tekan Allow / Izinkan di HP. Jika offline/kosong, periksa kabel dan USB debugging.\n'
    [ "$NO_PAUSE" -eq 0 ] && [ -t 0 ] || fail 'HP belum siap.'
    printf 'Tekan Enter untuk periksa ulang, atau ketik Q lalu Enter untuk keluar: '
    IFS= read -r answer || finish 1
    case "$answer" in q|Q) finish 1;; esac
done
if ! "$ADB" -s "$SERIAL" shell pm path id.hansdev.forwarder >"$STATE_FILE" 2>&1; then
    cat "$STATE_FILE"
    fail 'Pemeriksaan APK gagal.'
fi
grep -q '^package:' "$STATE_FILE" || fail 'Pasang APK HansDev Forwarder pada HP ini dahulu.'
if ! "$ADB" -s "$SERIAL" shell cmd notification allow_listener id.hansdev.forwarder/.ForwardListener >"$STATE_FILE" 2>&1; then
    cat "$STATE_FILE"
    fail 'Aktivasi listener ditolak. Periksa akses notifikasi pada HP.'
fi
if ! "$ADB" -s "$SERIAL" shell settings get secure enabled_notification_listeners >"$STATE_FILE" 2>&1; then
    cat "$STATE_FILE"
    fail 'Pemeriksaan izin gagal.'
fi
if ! grep -F -e 'id.hansdev.forwarder/id.hansdev.forwarder.ForwardListener' -e 'id.hansdev.forwarder/.ForwardListener' "$STATE_FILE" >/dev/null; then
    fail 'Izin listener belum terverifikasi. Periksa akses notifikasi di HP.'
fi
printf '\nBERHASIL: izin listener HansDev Forwarder sudah aktif.\n'
"$ADB" -s "$SERIAL" shell am start -n id.hansdev.forwarder/.MainActivity >/dev/null 2>&1 || printf 'Buka HansDev Forwarder secara manual.\n'
printf 'Periksa status Listener terhubung. Jika pengiriman dijeda, aktifkan di aplikasi.\n'
finish 0
