#!/bin/bash

# --- CONFIGURACION ---
DB_NAME="inventario_isp"
DB_USER="app_isp"
DB_PASS=$(openssl rand -base64 12) # Genera clave aleatoria

# --- COLORES ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}=== INSTALADOR TODO-EN-UNO SISTEMA ISP ===${NC}"

# 1. INSTALAR REQUISITOS
echo -e "${GREEN}[1/5] Instalando servidor y PHP...${NC}"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y apache2 mariadb-server git unzip curl
apt-get install -y php libapache2-mod-php php-mysql php-zip php-mbstring php-curl php-xml php-gd

# 2. CONFIGURAR USUARIO DE BASE DE DATOS
echo -e "${GREEN}[2/5] Configurando accesos BD...${NC}"
mysql -e "CREATE DATABASE IF NOT EXISTS ${DB_NAME};"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

# 3. CREAR TABLAS E INSERTAR DATOS (Aquí reemplazamos al archivo SQL.sql)
echo -e "${GREEN}[3/5] Construyendo estructura de la Base de Datos...${NC}"
mysql ${DB_NAME} <<EOF
-- TABLA CONFIGURACION
CREATE TABLE IF NOT EXISTS \`configuracion\` (
  \`clave\` varchar(50) NOT NULL,
  \`valor\` text DEFAULT NULL,
  PRIMARY KEY (\`clave\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO \`configuracion\` VALUES 
('empresaNombre', '"MI ISP"'),
('dashboardConfig', '["ONU","Router","Cable Drop"]'),
('alertThresholds', '{"ONU":5}');

-- TABLA USUARIOS (Admin por defecto: admin / 1234)
CREATE TABLE IF NOT EXISTS \`usuarios\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`user\` varchar(50) NOT NULL,
  \`pass\` varchar(255) NOT NULL,
  \`admin\` tinyint(1) DEFAULT 0,
  \`rol\` varchar(20) NOT NULL DEFAULT 'admin',
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`user\` (\`user\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO \`usuarios\` (\`user\`, \`pass\`, \`admin\`, \`rol\`) VALUES
('admin', '\$2y\$10\$uh2bSCNFooCiYy12FDXSkeKMzN8ibZwENdW0F/WFL.zaFE1ELPzjO', 1, 'admin');

-- TABLA INVENTARIO
CREATE TABLE IF NOT EXISTS \`inventario\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`codigo\` varchar(50) DEFAULT NULL,
  \`nombre\` varchar(100) DEFAULT NULL,
  \`serial\` varchar(100) DEFAULT NULL,
  \`categoria\` varchar(50) DEFAULT NULL,
  \`estado\` varchar(50) DEFAULT 'Disponible',
  \`oficina\` varchar(100) DEFAULT NULL,
  \`fecha\` datetime DEFAULT NULL,
  \`usuario\` varchar(50) DEFAULT NULL,
  \`persona\` varchar(100) DEFAULT NULL,
  \`cliente\` varchar(150) DEFAULT NULL,
  \`uso\` varchar(100) DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA HISTORIAL
CREATE TABLE IF NOT EXISTS \`historial\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`fecha\` datetime DEFAULT NULL,
  \`accion\` varchar(50) DEFAULT NULL,
  \`codigo\` varchar(50) DEFAULT NULL,
  \`nombre_articulo\` varchar(100) DEFAULT NULL,
  \`persona\` varchar(100) DEFAULT NULL,
  \`tecnico\` varchar(50) DEFAULT NULL,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA PERSONAL
CREATE TABLE IF NOT EXISTS \`personal\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`nombre\` varchar(100) DEFAULT NULL,
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`nombre\` (\`nombre\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA OFICINAS
CREATE TABLE IF NOT EXISTS \`oficinas\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`nombre\` varchar(100) DEFAULT NULL,
  PRIMARY KEY (\`id\`),
  UNIQUE KEY \`nombre\` (\`nombre\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA DEVOLUCIONES
CREATE TABLE IF NOT EXISTS \`devoluciones\` (
  \`id\` int(11) NOT NULL AUTO_INCREMENT,
  \`fecha\` datetime DEFAULT NULL,
  \`codigo\` varchar(50) DEFAULT NULL,
  \`nombre\` varchar(100) DEFAULT NULL,
  \`categoria\` varchar(50) DEFAULT NULL,
  \`razon\` varchar(100) DEFAULT NULL,
  \`tipoFalla\` varchar(255) DEFAULT NULL,
  \`clienteOLugar\` varchar(255) DEFAULT NULL,
  \`asignadoA\` varchar(100) DEFAULT NULL,
  \`tecnico\` varchar(50) DEFAULT NULL,
  \`reutilizado\` tinyint(1) DEFAULT 0,
  PRIMARY KEY (\`id\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
EOF

# 4. DESCARGAR PHP (API) Y HTML
echo -e "${GREEN}[4/5] Descargando aplicación...${NC}"
cd /var/www/html
rm -f index.html

read -p "Introduce URL del Repo GitHub: " REPO_URL
if [ ! -z "$REPO_URL" ]; then
    git clone $REPO_URL temp_repo
    cp -r temp_repo/* .
    rm -rf temp_repo .git
    echo "Archivos descargados."
else
    echo -e "${YELLOW}Saltando descarga (se asume carga manual).${NC}"
fi

# Conectar API con la BD (Reemplazo de contraseña)
if [ -f "api.php" ]; then
    sed -i "s/UnaClave_MuyDificil_99\\$/${DB_PASS}/g" api.php
    echo "API conectada a la base de datos."
else
    echo -e "${RED}¡ALERTA! No se encontró api.php. Sube los archivos manualmente.${NC}"
fi

# 5. EXTRAS
echo -e "${GREEN}[5/5] Finalizando...${NC}"
# PHPMailer
if [ ! -d "PHPMailer" ]; then
    git clone https://github.com/PHPMailer/PHPMailer.git
fi

# Permisos
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html

echo -e "${GREEN}=== ¡INSTALACIÓN EXITOSA! ===${NC}"
echo -e "BD: ${DB_USER} / ${DB_PASS}"
echo -e "Accede en: http://$(hostname -I | cut -d' ' -f1)"
