# Azure VM AI Deployment (Chatbot + Document Generator)

This folder provides automation to deploy Ollama models to an Azure VM and optionally update Laravel environment settings.

## Files

- provision_ai_vm.sh: Runs on VM. Installs Ollama, pulls models, optional .env update.
- upload_and_deploy.sh: Run locally from bash to upload and execute provisioning.
- upload_and_deploy.ps1: Run locally from PowerShell to upload and execute provisioning.

## Models used by your current backend

- Chatbot routing models:
  - aslaw-civil
  - aslaw-corporate
  - aslaw-criminal
  - aslaw-general
- Document generator model:
  - llama3 (configurable via DOCUMENT_GENERATOR_MODEL)
- Optional scope/classifier model:
  - qwen2.5:7b

## Bash usage

1. Open shell in this folder.
2. Run:

   VM_HOST=<your-vm-public-ip> VM_USER=azureuser SSH_KEY=~/.ssh/id_rsa ./upload_and_deploy.sh

3. Optional with backend .env update on the VM:

   VM_HOST=<your-vm-public-ip> VM_USER=azureuser SSH_KEY=~/.ssh/id_rsa APP_ENV_FILE=/var/www/aslaw-back-end/.env DOC_MODEL=llama3 ./upload_and_deploy.sh

## PowerShell usage

1. Open PowerShell in this folder.
2. Run:

   .\upload_and_deploy.ps1 -VmHost <your-vm-public-ip> -VmUser azureuser -SshKeyPath C:\Users\you\.ssh\id_rsa

3. Optional with backend .env update:

   .\upload_and_deploy.ps1 -VmHost <your-vm-public-ip> -VmUser azureuser -SshKeyPath C:\Users\you\.ssh\id_rsa -AppEnvFile /var/www/aslaw-back-end/.env -DocModel llama3

## Important network/security notes

- Keep port 11434 private whenever possible.
- In Azure NSG, allow 11434 only from trusted private IP/subnet (your app VM/subnet).
- Do not keep secrets in repository files.
