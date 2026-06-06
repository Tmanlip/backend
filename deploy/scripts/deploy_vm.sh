#!/usr/bin/env bash
set -euo pipefail

# One-shot deployment bootstrap for ASLAW backend on Azure VM (Ubuntu)
# Usage:
#   sudo bash deploy/scripts/deploy_vm.sh \
#     --repo-url https://github.com/your-org/your-repo.git \
#     --branch main \
#     --app-dir /var/www/aslaw-backend \
#     --domain api.yourdomain.com \
#     --model-dir /opt/aslaw-model

REPO_URL=""
BRANCH="main"
APP_DIR="/var/www/aslaw-backend"
DOMAIN=""
MODEL_DIR="/opt/aslaw-model"
INSTALL_Ollama="true"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --repo-url) REPO_URL="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --app-dir) APP_DIR="$2"; shift 2 ;;
    --domain) DOMAIN="$2"; shift 2 ;;
    --model-dir) MODEL_DIR="$2"; shift 2 ;;
    --skip-ollama) INSTALL_Ollama="false"; shift 1 ;;
    *) echo "Unknown argument: $1"; exit 1 ;;
  esac
done

if [[ -z "$REPO_URL" ]]; then
  echo "Missing --repo-url"
  exit 1
fi

if [[ -z "$DOMAIN" ]]; then
  echo "Missing --domain"
  exit 1
fi

echo "[1/10] Installing packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y \
  ca-certificates curl gnupg lsb-release software-properties-common \
  nginx git unzip libreoffice \
  php php-cli php-fpm php-mbstring php-xml php-zip php-curl php-pgsql \
  composer

echo "[2/10] Cloning/updating repository"
mkdir -p "$(dirname "$APP_DIR")"
if [[ -d "$APP_DIR/.git" ]]; then
  git -C "$APP_DIR" fetch --all --prune
  git -C "$APP_DIR" checkout "$BRANCH"
  git -C "$APP_DIR" pull origin "$BRANCH"
else
  git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi

echo "[3/10] Installing PHP dependencies"
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader

if [[ ! -f "$APP_DIR/.env" ]]; then
  cp "$APP_DIR/.env.example" "$APP_DIR/.env"
  echo "Created .env from .env.example"
fi

if ! grep -q '^APP_KEY=' "$APP_DIR/.env" || grep -q '^APP_KEY=$' "$APP_DIR/.env"; then
  php artisan key:generate --force
fi

echo "[4/10] Laravel optimize and migration"
php artisan config:clear
php artisan cache:clear
php artisan migrate --force
php artisan config:cache
php artisan route:cache

echo "[5/10] Fixing ownership/permissions"
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "[6/10] Configuring Nginx"
cp "$APP_DIR/deploy/nginx/aslaw-api.conf" /etc/nginx/sites-available/aslaw-api.conf
sed -i "s|api.yourdomain.com|$DOMAIN|g" /etc/nginx/sites-available/aslaw-api.conf
sed -i "s|/var/www/aslaw-backend|$APP_DIR|g" /etc/nginx/sites-available/aslaw-api.conf

ln -sf /etc/nginx/sites-available/aslaw-api.conf /etc/nginx/sites-enabled/aslaw-api.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable nginx
systemctl restart nginx

echo "[7/10] Enabling queue worker service"
cp "$APP_DIR/deploy/systemd/aslaw-queue-worker.service" /etc/systemd/system/aslaw-queue-worker.service
sed -i "s|/var/www/aslaw-backend|$APP_DIR|g" /etc/systemd/system/aslaw-queue-worker.service

systemctl daemon-reload
systemctl enable aslaw-queue-worker
systemctl restart aslaw-queue-worker

if [[ "$INSTALL_Ollama" == "true" ]]; then
  echo "[8/10] Installing Ollama"
  curl -fsSL https://ollama.com/install.sh | sh
  systemctl enable ollama || true
  systemctl restart ollama || true

  echo "[9/10] Building ASLAW Ollama models (if Modelfiles exist)"
  if [[ -f "$MODEL_DIR/Modelfile.general" ]]; then
    ollama create aslaw-general -f "$MODEL_DIR/Modelfile.general" || true
  fi
  if [[ -f "$MODEL_DIR/Modelfile.civil" ]]; then
    ollama create aslaw-civil -f "$MODEL_DIR/Modelfile.civil" || true
  fi
  if [[ -f "$MODEL_DIR/Modelfile.corporate" ]]; then
    ollama create aslaw-corporate -f "$MODEL_DIR/Modelfile.corporate" || true
  fi
  if [[ -f "$MODEL_DIR/Modelfile.criminal" ]]; then
    ollama create aslaw-criminal -f "$MODEL_DIR/Modelfile.criminal" || true
  fi
else
  echo "[8-9/10] Skipped Ollama install/model build"
fi

echo "[10/10] Installing TLS certificate"
apt-get install -y certbot python3-certbot-nginx
certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m admin@"$DOMAIN" --redirect || true

echo "Deployment bootstrap completed."
echo "Next: update $APP_DIR/.env with production secrets and restart php-fpm + nginx + queue worker."
