#!/bin/bash
set -Eeuo pipefail
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077

(( EUID == 0 )) || { echo "Executer en root" >&2; exit 1; }
[[ "$#" == 0 ]] || exit 2

# Preserve any existing local customization before removing these two files.
check=/usr/local/sbin/wikidebates-luasandbox-check
cron=/etc/cron.d/wikidebates-luasandbox
for target in "$check" "$cron"; do
	if [[ -L "$target" || ( -e "$target" && ! -f "$target" ) ]]; then
		echo "Cible inattendue : $target" >&2
		exit 1
	fi
done

exec 9>/run/wikidebates-luasandbox.lock
flock -w 180 9 || { echo "Controle en cours : reessayer" >&2; exit 1; }

backup=$(mktemp -d /root/wikidebates-luasandbox-backup.XXXXXXXX)
for target in "$check" "$cron"; do
	if [[ -f "$target" ]]; then
		cp -p -- "$target" "$backup/"
	fi
done

rm -f -- "$cron" "$check"
echo "Surveillance retiree ; sauvegarde : $backup"
echo "Paquet LuaSandbox, configuration PHP et journaux conserves."
