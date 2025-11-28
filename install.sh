#!/bin/bash

# --- CONFIGURACIÓN ---
DB_NAME="inventario_isp"
DB_USER="app_isp"
# CAMBIO IMPORTANTE: Usamos 'hex' en lugar de 'base64' para evitar símbolos raros
DB_PASS=$(openssl rand -hex 12)

# --- COLORES ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}=== INSTALADOR FINAL SISTEMA ISP ===${NC}"

# 1. INSTALAR REQUISITOS
echo -e "${GREEN}[1/5] Instalando servidor web y base de datos...${NC}"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y apache2 mariadb-server git unzip curl
apt-get install -y php libapache2-mod-php php-mysql php-zip php-mbstring php-curl php-xml php-gd

# 2. CONFIGURAR BASE DE DATOS
echo -e "${GREEN}[2/5] Configurando accesos DB...${NC}"
# Creamos la BD y el usuario con la nueva clave segura
mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# 3. CREAR TABLAS (SQL INCORPORADO)
echo -e "${GREEN}[3/5] Construyendo tablas...${NC}"
mysql ${DB_NAME} <<EOF
CREATE TABLE IF NOT EXISTS \`configuracion\` (\`clave\` varchar(50) NOT NULL, \`valor\` text DEFAULT NULL, PRIMARY KEY (\`clave\`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO \`configuracion\` VALUES ('empresaNombre', '"MI ISP"'),('dashboardConfig', '["ONU","Router","Cable Drop"]'),('alertThresholds', '{"ONU":5}');

CREATE TABLE IF NOT EXISTS \`usuarios\` (\`id\` int(11) NOT NULL AUTO_INCREMENT, \`user\` varchar(50) NOT NULL, \`pass\` varchar(255) NOT NULL, \`admin\` tinyint(1) DEFAULT 0, \`rol\` varchar(20) NOT NULL DEFAULT 'admin', PRIMARY KEY (\`id\`), UNIQUE KEY \`user\` (\`user\`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO \`usuarios\` (\`user\`, \`pass\`, \`admin\`, \`rol\`) VALUES ('admin', '\$2y\$10\$uh2bSCNFooCiYy12FDXSkeKMzN8ibZwENdW0F/WFL.zaFE1ELPzjO', 1, 'admin');

CREATE TABLE IF NOT EXISTS \`inventario\` (\`id\` int(11) NOT NULL AUTO_INCREMENT, \`codigo\` varchar(50) DEFAULT NULL, \`nombre\` varchar(100) DEFAULT NULL, \`serial\` varchar(100) DEFAULT NULL, \`categoria\` varchar(50) DEFAULT NULL, \`estado\` varchar(50) DEFAULT 'Disponible', \`oficina\` varchar(100) DEFAULT NULL, \`fecha\` datetime DEFAULT NULL, \`usuario\` varchar(50) DEFAULT NULL, \`persona\` varchar(100) DEFAULT NULL, \`cliente\` varchar(150) DEFAULT NULL, \`uso\` varchar(100) DEFAULT NULL, PRIMARY KEY (\`id\`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`historial\` (\`id\` int(11) NOT NULL AUTO_INCREMENT, \`fecha\` datetime DEFAULT NULL, \`accion\` varchar(50) DEFAULT NULL, \`codigo\` varchar(50) DEFAULT NULL, \`nombre_articulo\` varchar(100) DEFAULT NULL, \`persona\` varchar(100) DEFAULT NULL, \`tecnico\` varchar(50) DEFAULT NULL, PRIMARY KEY (\`id\`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`personal\` (\`id\` int(11) NOT NULL AUTO_INCREMENT, \`nombre\` varchar(100) DEFAULT NULL, PRIMARY KEY (\`id\`), UNIQUE KEY \`nombre\` (\`nombre\`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`oficinas\` (\`id\` int(11) NOT NULL AUTO_INCREMENT, \`nombre\` varchar(100) DEFAULT NULL, PRIMARY KEY (\`id\`), UNIQUE KEY \`nombre\` (\`nombre\`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS \`devoluciones\` (\`id\` int(11) NOT NULL AUTO_INCREMENT, \`fecha\` datetime DEFAULT NULL, \`codigo\` varchar(50) DEFAULT NULL, \`nombre\` varchar(100) DEFAULT NULL, \`categoria\` varchar(50) DEFAULT NULL, \`razon\` varchar(100) DEFAULT NULL, \`tipoFalla\` varchar(255) DEFAULT NULL, \`clienteOLugar\` varchar(255) DEFAULT NULL, \`asignadoA\` varchar(100) DEFAULT NULL, \`tecnico\` varchar(50) DEFAULT NULL, \`reutilizado\` tinyint(1) DEFAULT 0, PRIMARY KEY (\`id\`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOF

# 4. GESTIÓN DE ARCHIVOS WEB
echo -e "${GREEN}[4/5] Verificando archivos del sistema...${NC}"
cd /var/www/html

read -p "Introduce URL del Repo GitHub (Presiona ENTER si ya subiste los archivos manualmente): " REPO_URL

if [ ! -z "$REPO_URL" ]; then
    # Solo borramos si el usuario confirma que quiere descargar
    rm -f index.html 
    git clone $REPO_URL temp_repo
    cp -r temp_repo/* .
    rm -rf temp_repo .git
    echo "Archivos descargados desde GitHub."
else
    echo -e "${YELLOW}Modo Manual seleccionado. Usando archivos existentes.${NC}"
fi

# 5. CONEXIÓN API (LA PARTE QUE FALLABA)
if [ -f "api.php" ]; then
    # Esta expresión REGEX busca: $pass = 'cualquier_cosa'; y la reemplaza por la nueva clave
    # Esto evita errores si tu clave vieja no coincide o tiene símbolos raros.
    sed -i "s/\$pass = '.*';/\$pass = '${DB_PASS}';/" api.php
    echo "Clave de API actualizada correctamente."
else
    echo -e "${RED}¡ALERTA! No se encontró api.php. Asegúrate de subirlo.${NC}"
fi

# 6. EXTRAS Y PERMISOS
echo -e "${GREEN}[5/5] Finalizando...${NC}"
if [ ! -d "PHPMailer" ]; then
    git clone https://github.com/PHPMailer/PHPMailer.git
fi

chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html

echo -e "${GREEN}=== ¡INSTALACIÓN COMPLETADA EXITOSAMENTE! ===${NC}"
echo -e "BD Usuario: ${DB_USER}"
echo -e "BD Clave:   ${DB_PASS}"
echo -e "---------------------------------------------"
echo -e "Accede al sistema: http://$(hostname -I | cut -d' ' -f1)"
