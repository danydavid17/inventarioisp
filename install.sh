#!/bin/bash

# --- CONFIGURACION INICIAL ---
DB_NAME="inventario_isp"
DB_USER="app_isp"
# Generamos una clave segura aleatoria de 16 caracteres
DB_PASS=$(openssl rand -base64 16)

# --- COLORES ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}=== INSTALADOR AUTOMÁTICO SISTEMA ISP (V2) ===${NC}"

# 1. ACTUALIZAR SISTEMA E INSTALAR DEPENDENCIAS
echo -e "${GREEN}[1/6] Actualizando servidor e instalando requisitos...${NC}"
# Forzamos modo no interactivo para que no pregunte cosas durante la instalación
export DEBIAN_FRONTEND=noninteractive
apt update -y
apt install -y apache2 mariadb-server git unzip curl
apt install -y php libapache2-mod-php php-mysql php-zip php-mbstring php-curl php-xml php-gd

# 2. CONFIGURAR BASE DE DATOS
echo -e "${GREEN}[2/6] Configurando Base de Datos...${NC}"
mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# 3. LIMPIEZA WEB
echo -e "${GREEN}[3/6] Preparando directorio web...${NC}"
cd /var/www/html
# Solo borramos el index.html por defecto de Apache, intentamos respetar otros archivos si existen
rm -f index.html

# 4. DESCARGAR SISTEMA
echo -e "${GREEN}[4/6] Obteniendo archivos del sistema...${NC}"
# Preguntamos si usar un repo o buscar archivos locales
read -p "Introduce URL del Repo GitHub (o presiona ENTER si subiste los archivos manualmente): " REPO_URL

if [ ! -z "$REPO_URL" ]; then
    git clone $REPO_URL temp_repo
    mv temp_repo/* .
    rm -rf temp_repo .git
    echo "Archivos descargados desde GitHub."
else
    echo -e "${YELLOW}Omitiendo descarga. Se asume que subiste los archivos (api.php, index.html, SQL.sql) manualmente.${NC}"
fi

# 5. IMPORTAR SQL Y CONFIGURAR API
echo -e "${GREEN}[5/6] Configurando Backend y BD...${NC}"
if [ -f "SQL.sql" ]; then
    mysql ${DB_NAME} < SQL.sql
    echo "Base de datos importada."
else
    echo -e "${RED}ERROR: No se encontró el archivo SQL.sql${NC}"
fi

if [ -f "api.php" ]; then
    # Reemplazo seguro de la contraseña
    sed -i "s/UnaClave_MuyDificil_99\\$/${DB_PASS}/g" api.php
    echo "Clave de base de datos actualizada en api.php."
else
    echo -e "${RED}ERROR: No se encontró api.php${NC}"
fi

# 6. INSTALAR PHPMAILER (VERIFICACIÓN INTELIGENTE)
echo -e "${GREEN}[6/6] Verificando librería de correos (PHPMailer)...${NC}"
if [ -d "PHPMailer" ]; then
    echo -e "${YELLOW}La carpeta PHPMailer ya existe. Saltando descarga.${NC}"
else
    echo "La carpeta no existe. Descargando PHPMailer..."
    git clone https://github.com/PHPMailer/PHPMailer.git
    
    if [ -d "PHPMailer" ]; then
        echo "PHPMailer instalado correctamente."
    else
        echo -e "${RED}Error al descargar PHPMailer. Revisa tu conexión.${NC}"
    fi
fi

# 7. AJUSTAR PERMISOS FINALES
echo -e "${GREEN}[FIN] Ajustando permisos...${NC}"
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html

echo -e "${GREEN}=============================================${NC}"
echo -e "${GREEN}      INSTALACIÓN COMPLETADA                 ${NC}"
echo -e "${GREEN}=============================================${NC}"
echo -e "BD Usuario: ${DB_USER}"
echo -e "BD Clave:   ${DB_PASS}"
echo -e "URL Acceso: http://$(hostname -I | cut -d' ' -f1)"