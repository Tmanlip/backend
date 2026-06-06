ASLAW Azure VM deployment assets

Included files
- deploy/nginx/aslaw-api.conf: Nginx server block for Laravel API
- deploy/systemd/aslaw-queue-worker.service: systemd worker for Laravel queue
- deploy/scripts/deploy_vm.sh: one-shot bootstrap script for Ubuntu VM

Quick start on VM
1. Copy repository to the VM.
2. Ensure model files are present on VM, for example:
   - /opt/aslaw-model/Modelfile.general
   - /opt/aslaw-model/Modelfile.civil
   - /opt/aslaw-model/Modelfile.corporate
   - /opt/aslaw-model/Modelfile.criminal
3. Run:
   sudo bash deploy/scripts/deploy_vm.sh \
     --repo-url https://github.com/your-org/your-repo.git \
     --branch main \
     --app-dir /var/www/aslaw-backend \
     --domain api.yourdomain.com \
     --model-dir /opt/aslaw-model

Required .env keys to set after bootstrap
- APP_ENV=production
- APP_URL=https://api.yourdomain.com
- APP_FRONTEND_URL=https://your-frontend-domain
- CORS_ALLOWED_ORIGINS=https://your-frontend-domain
- DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
- MONGODB_URI and MONGODB_DATABASE
- OLLAMA_BASE_URL=http://127.0.0.1:11434
- DOCUMENT_GENERATOR_MODEL=llama3
- CHATBOT_FALLBACK_MODEL=llama3
- LIBREOFFICE_PATH=/usr/bin/soffice
- AZURE_STORAGE_NAME, AZURE_STORAGE_CONTAINER, AZURE_STORAGE_KEY, AZURE_STORAGE_CONNECTION_STRING
- MAIL_* values for production mail relay

Useful checks
- sudo systemctl status nginx
- sudo systemctl status aslaw-queue-worker
- sudo systemctl status ollama
- curl -i https://api.yourdomain.com/api/ping
- curl -i https://api.yourdomain.com/api/document-generator/health
