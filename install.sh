#!/bin/bash
set -euo pipefail

APP_DIR="/docker/cdn"
REPO="https://github.com/AlexanderGalchenko/kubit_cdm.git"

apt-get update
apt-get install -y git docker.io docker-compose-plugin
systemctl enable --now docker

mkdir -p /docker
if [ ! -d "$APP_DIR/.git" ]; then
  rm -rf "$APP_DIR"
  git clone "$REPO" "$APP_DIR"
fi

cd "$APP_DIR"
mkdir -p data cache logs

if ! grep -q "ADMIN_PASSWORD:" docker-compose.yml; then
  echo "docker-compose.yml не найден или повреждён"
  exit 1
fi

PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 20)"
SECRET="$(openssl rand -hex 32)"
sed -i "s/ADMIN_PASSWORD: change-me-now/ADMIN_PASSWORD: ${PASS}/" docker-compose.yml
sed -i "s/ADMIN_SESSION_SECRET: change-this-secret/ADMIN_SESSION_SECRET: ${SECRET}/" docker-compose.yml

docker compose up -d --build

echo ""
echo "Kubit CDN установлен."
echo "URL: http://SERVER_IP/admin/"
echo "Login: admin"
echo "Password: ${PASS}"
