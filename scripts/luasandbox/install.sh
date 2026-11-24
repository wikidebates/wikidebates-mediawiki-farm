#!/bin/bash
set -Eeuo pipefail

export PATH=/usr/sbin:/usr/bin:/sbin:/bin
umask 077

if (( EUID != 0 )); then
	echo "Executer en root depuis une copie de confiance, hors du webroot." >&2
	exit 1
fi
[[ "$#" == 0 ]] || exit 2

source_dir=$(cd -- "$(dirname -- "$0")" && pwd -P)
check="$source_dir/wikidebates-luasandbox-check"
cron="$source_dir/wikidebates-luasandbox.cron"
check_target=/usr/local/sbin/wikidebates-luasandbox-check
cron_target=/etc/cron.d/wikidebates-luasandbox

# Do not replace existing symlinks or unusual objects.
for target in "$check_target" "$cron_target"; do
	if [[ -L "$target" || ( -e "$target" && ! -f "$target" ) ]]; then
		echo "Cible inattendue : $target" >&2
		exit 1
	fi
done

bash -n "$check"
[[ "$(cat -- "$cron")" == '45 * * * * root /usr/local/sbin/wikidebates-luasandbox-check' ]] ||
	{ echo "Cron inattendu" >&2; exit 1; }

# Validate and, if needed, repair before replacing the existing scheduled task.
# --wait prevents a skipped concurrent check from being treated as success.
bash "$check" --wait
apt-mark manual php-luasandbox

backup=$(mktemp -d /root/wikidebates-luasandbox-backup.XXXXXXXX)
for target in "$check_target" "$cron_target"; do
	if [[ -f "$target" ]]; then
		cp -p -- "$target" "$backup/"
	fi
done
echo "Sauvegarde des fichiers existants : $backup"

# Per-file atomic replacement. Install the executable before publishing the cron.
check_tmp=
cron_tmp=
cleanup() {
	[[ -z "$check_tmp" ]] || rm -f -- "$check_tmp"
	[[ -z "$cron_tmp" ]] || rm -f -- "$cron_tmp"
}
trap cleanup EXIT

check_tmp=$(mktemp /usr/local/sbin/.wikidebates-luasandbox.XXXXXXXX)
cron_tmp=$(mktemp /etc/cron.d/.wikidebates-luasandbox.XXXXXXXX)
install -o root -g root -m 0755 -- "$check" "$check_tmp"
install -o root -g root -m 0644 -- "$cron" "$cron_tmp"
mv -T -- "$check_tmp" "$check_target"
check_tmp=
mv -T -- "$cron_tmp" "$cron_target"
cron_tmp=

echo "Controle LuaSandbox installe : toutes les heures a la minute 45."
