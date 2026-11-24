#!/bin/bash
set -euo pipefail

HOST="45.79.220.248"
USER="root"
DEST="/var/www/test-electoral-espana"
DOMAIN="test-electoral-espana.45.79.220.248.sslip.io"
EMAIL="edfasano70@gmail.com"
SSH_OPTS="-o StrictHostKeyChecking=no -o PreferredAuthentications=password -o PubkeyAuthentication=no"

if [ -n "${SSHPASS_FILE:-}" ]; then
  PASS_OPTS=(-f "$SSHPASS_FILE")
elif [ -n "${SSHPASS:-}" ]; then
  PASS_OPTS=(-e)
else
  echo "Definí SSHPASS o SSHPASS_FILE con la contraseña SSH antes de ejecutar." >&2
  exit 1
fi

echo "→ Preparando $DEST en $USER@$HOST"
sshpass "${PASS_OPTS[@]}" ssh $SSH_OPTS "$USER@$HOST" "mkdir -p $DEST"

echo "→ rsync"
sshpass "${PASS_OPTS[@]}" rsync -az --delete \
  --exclude '.git' --exclude 'deploy' --exclude 'deploy.sh' --exclude 'AGENTS.md' \
  -e "ssh $SSH_OPTS" ./ "$USER@$HOST:$DEST/"

echo "→ Subiendo vhost de referencia"
sshpass "${PASS_OPTS[@]}" scp $SSH_OPTS \
  deploy/test-electoral-espana.conf "$USER@$HOST:/tmp/$DOMAIN.conf"

echo "→ Permisos, vhost y recarga de Apache"
sshpass "${PASS_OPTS[@]}" ssh $SSH_OPTS "$USER@$HOST" "
  set -e
  chown -R www-data:www-data $DEST
  find $DEST -type d -exec chmod 755 {} +
  find $DEST -type f -exec chmod 644 {} +
  mkdir -p /var/lib/test-electoral-espana
  chown www-data:www-data /var/lib/test-electoral-espana
  chmod 775 /var/lib/test-electoral-espana
  if [ ! -f /etc/apache2/sites-available/$DOMAIN.conf ]; then
    install -m 644 /tmp/$DOMAIN.conf /etc/apache2/sites-available/$DOMAIN.conf
    a2ensite $DOMAIN >/dev/null
    apache2ctl configtest
    systemctl reload apache2
    echo '   vhost instalado'
  else
    echo '   vhost ya existente (se conserva, p.ej. tras certbot)'
  fi
  rm -f /tmp/$DOMAIN.conf
"

echo "→ HTTPS (certbot, solo la primera vez)"
if ! sshpass "${PASS_OPTS[@]}" ssh $SSH_OPTS "$USER@$HOST" "[ -d /etc/letsencrypt/live/$DOMAIN ]"; then
  sshpass "${PASS_OPTS[@]}" ssh $SSH_OPTS "$USER@$HOST" \
    "certbot --apache -d $DOMAIN --redirect --non-interactive --agree-tos -m $EMAIL" \
    || echo "⚠ No se pudo emitir el certificado; el sitio queda disponible por HTTP."
fi

echo "✔ Deploy completo → https://$DOMAIN/"
