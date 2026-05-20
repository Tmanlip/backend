#!/usr/bin/env bash
set -euo pipefail

# Local helper (run from your machine) to upload and execute VM provisioning script.
# Example:
# VM_HOST=20.1.2.3 VM_USER=azureuser SSH_KEY=~/.ssh/id_rsa ./upload_and_deploy.sh

VM_HOST="${VM_HOST:-}"
VM_USER="${VM_USER:-azureuser}"
SSH_KEY="${SSH_KEY:-}"
REMOTE_DIR="${REMOTE_DIR:-~/aslaw-ai-deploy}"
MODEL_LIST="${MODEL_LIST:-aslaw-civil aslaw-corporate aslaw-criminal aslaw-general llama3 qwen2.5:7b}"
APP_ENV_FILE="${APP_ENV_FILE:-}"
DOC_MODEL="${DOC_MODEL:-llama3}"

if [[ -z "$VM_HOST" ]]; then
  echo "Set VM_HOST first."
  exit 1
fi

SSH_OPTS="-o StrictHostKeyChecking=accept-new"
if [[ -n "$SSH_KEY" ]]; then
  SSH_OPTS="$SSH_OPTS -i $SSH_KEY"
fi

echo "[1/3] Uploading script to VM"
ssh $SSH_OPTS "$VM_USER@$VM_HOST" "mkdir -p $REMOTE_DIR"
scp $SSH_OPTS "$(dirname "$0")/provision_ai_vm.sh" "$VM_USER@$VM_HOST:$REMOTE_DIR/provision_ai_vm.sh"

echo "[2/3] Running provisioning on VM"
REMOTE_CMD="chmod +x $REMOTE_DIR/provision_ai_vm.sh; MODEL_LIST='$MODEL_LIST' DOC_MODEL='$DOC_MODEL'"
if [[ -n "$APP_ENV_FILE" ]]; then
  REMOTE_CMD="$REMOTE_CMD APP_ENV_FILE='$APP_ENV_FILE'"
fi
REMOTE_CMD="$REMOTE_CMD sudo -E $REMOTE_DIR/provision_ai_vm.sh"

ssh $SSH_OPTS "$VM_USER@$VM_HOST" "$REMOTE_CMD"

echo "[3/3] Verifying models"
ssh $SSH_OPTS "$VM_USER@$VM_HOST" "ollama list"

echo "Deployment complete."
