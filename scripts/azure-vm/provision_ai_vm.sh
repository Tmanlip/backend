#!/usr/bin/env bash
set -euo pipefail

# Azure VM provisioning script for ASLAW AI workloads (chatbot + document-generator)
# - Installs Ollama
# - Configures Ollama service
# - Pulls required models
# - Optionally updates Laravel .env values

MODEL_LIST_DEFAULT="aslaw-civil aslaw-corporate aslaw-criminal aslaw-general llama3 qwen2.5:7b"
MODEL_LIST="${MODEL_LIST:-$MODEL_LIST_DEFAULT}"
OLLAMA_LISTEN="${OLLAMA_LISTEN:-0.0.0.0:11434}"
APP_ENV_FILE="${APP_ENV_FILE:-}"
DOC_MODEL="${DOC_MODEL:-llama3}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Please run as root or with sudo."
  exit 1
fi

echo "[1/6] Installing dependencies"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y curl ca-certificates jq

echo "[2/6] Installing Ollama (if needed)"
if ! command -v ollama >/dev/null 2>&1; then
  curl -fsSL https://ollama.com/install.sh | sh
else
  echo "Ollama already installed."
fi

echo "[3/6] Configuring Ollama service"
mkdir -p /etc/systemd/system/ollama.service.d
cat >/etc/systemd/system/ollama.service.d/override.conf <<EOF
[Service]
Environment="OLLAMA_HOST=${OLLAMA_LISTEN}"
EOF

systemctl daemon-reload
systemctl enable ollama
systemctl restart ollama

echo "[4/6] Waiting for Ollama API"
for i in {1..30}; do
  if curl -sf http://127.0.0.1:11434/api/tags >/dev/null; then
    break
  fi
  sleep 2
  if [[ "$i" -eq 30 ]]; then
    echo "Ollama API did not become ready in time."
    exit 1
  fi
done

echo "[5/6] Pulling models"
for model in $MODEL_LIST; do
  echo "Pulling: $model"
  ollama pull "$model"
done

echo "[6/6] Optional Laravel .env update"
upsert_env_key() {
  local key="$1"
  local value="$2"
  local file="$3"

  if grep -qE "^${key}=" "$file"; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$file"
  else
    printf "\n%s=%s\n" "$key" "$value" >>"$file"
  fi
}

if [[ -n "$APP_ENV_FILE" ]]; then
  if [[ ! -f "$APP_ENV_FILE" ]]; then
    echo "APP_ENV_FILE not found: $APP_ENV_FILE"
    exit 1
  fi

  cp "$APP_ENV_FILE" "${APP_ENV_FILE}.bak.$(date +%Y%m%d%H%M%S)"
  upsert_env_key "OLLAMA_BASE_URL" "http://127.0.0.1:11434" "$APP_ENV_FILE"
  upsert_env_key "DOCUMENT_GENERATOR_MODEL" "$DOC_MODEL" "$APP_ENV_FILE"
  echo "Updated: $APP_ENV_FILE"
fi

echo "Done."
echo "Models installed: $MODEL_LIST"

echo "Recommended NSG rule: allow inbound 11434 only from your app subnet/private IPs."
