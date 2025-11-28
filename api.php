<?php
// 1. SEGURIDAD Y CONFIGURACIÓN
session_start();
header('Content-Type: application/json');
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

date_default_timezone_set('America/Santo_Domingo');
error_reporting(0); 
ini_set('display_errors', 0);

$host = 'localhost';
$db   = 'inventario_isp';
$user = 'app_isp';            
$pass = 'UnaClave_MuyDificil_99$'; 
$charset = 'utf8mb4';

// --- CARGA SEGURA DE PHPMAILER ---
$mailAvailable = false;
if (file_exists('PHPMailer/src/Exception.php') && 
    file_exists('PHPMailer/src/PHPMailer.php') && 
    file_exists('PHPMailer/src/SMTP.php')) {
        require 'PHPMailer/src/Exception.php';
        require 'PHPMailer/src/PHPMailer.php';
        require 'PHPMailer/src/SMTP.php';
        $mailAvailable = true;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [ PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false ];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '-04:00';");
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno de conexión BD']);
    exit;
}

// --- FUNCIONES AUXILIARES ---
function enviarCorreoSeguro($configs, $subject, $body, $attachmentContent = null, $attachmentName = null) {
    global $mailAvailable;
    if (!$mailAvailable) return ['ok' => false, 'error' => 'Falta carpeta PHPMailer'];
    $smtpConf = $configs;
    if (empty($smtpConf['host']) || empty($smtpConf['receiver'])) return ['ok' => false, 'error' => 'Configuración vacía'];
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug = 0; $mail->isSMTP(); $mail->Host = $smtpConf['host']; $mail->SMTPAuth = true; 
        $mail->Username = $smtpConf['user']; $mail->Password = $smtpConf['pass']; $mail->Port = $smtpConf['port']; 
        $mail->Timeout = 20; 
        if (!empty($smtpConf['tls'])) $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; else $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->setFrom($smtpConf['from'] ?? $smtpConf['user'], 'Sistema ISP'); $mail->addAddress($smtpConf['receiver']);
        $mail->isHTML(true); $mail->Subject = $subject; $mail->Body = $body;
        if ($attachmentContent && $attachmentName) $mail->addStringAttachment($attachmentContent, $attachmentName);
        $mail->send(); return ['ok' => true, 'error' => ''];
    } catch (Exception $e) { return ['ok' => false, 'error' => $mail->ErrorInfo]; }
}

function verificarStockYAlertar($pdo, $categoria, $oficina) {
    try {
        $stmtConf = $pdo->query("SELECT * FROM configuracion"); $configs = [];
        while ($row = $stmtConf->fetch()) { $configs[$row['clave']] = json_decode($row['valor'], true); }
        $umbrales = $configs['alertThresholds'] ?? []; $smtpConf = $configs['smtpConfig'] ?? [];
        $sql = "SELECT COUNT(*) FROM inventario WHERE categoria = ? AND estado = 'Disponible'"; $params = [$categoria];
        if (!empty($oficina)) { $sql .= " AND oficina = ?"; $params[] = $oficina; $nombreUbicacion = "Oficina: " . $oficina; } else { $sql .= " AND (oficina IS NULL OR oficina = '')"; $nombreUbicacion = "Oficina: General"; }
        $stmt = $pdo->prepare($sql); $stmt->execute($params); $stockActual = $stmt->fetchColumn(); $limite = $umbrales[$categoria] ?? 5;
        if ($stockActual <= $limite) {
            $asunto = ($stockActual == 0) ? "AGOTADO: $categoria" : "Bajo Stock: $categoria";
            $body = "<h2>⚠️ $asunto</h2><p>Ubicación: $nombreUbicacion</p><p>Stock actual: <strong>$stockActual</strong></p>";
            enviarCorreoSeguro($smtpConf, $asunto, $body); 
        }
    } catch (Exception $e) { }
}

function generarBackupSQL($pdo, $dbName) {
    $sql = "-- RESPALDO AUTOMATICO: $dbName\n-- Fecha: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n";
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $sql .= "\n" . $row[1] . ";\n";
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as $row) {
            $sql .= "INSERT INTO `$table` VALUES("; $values = [];
            foreach ($row as $data) { $values[] = ($data === null) ? "NULL" : $pdo->quote($data); }
            $sql .= implode(", ", $values) . ");\n";
        }
    }
    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";
    return $sql;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? '';

// Manejo especial para subida de archivos (POST normal, no JSON raw)
if ($action === 'restoreBackup' && isset($_FILES['backupFile'])) {
    if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) { echo json_encode(['success' => false, 'message' => 'No autorizado']); exit; }
    
    if (!class_exists('ZipArchive')) { echo json_encode(['success' => false, 'message' => 'Librería ZIP no instalada en servidor.']); exit; }

    $file = $_FILES['backupFile'];
    if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['success' => false, 'message' => 'Error al subir archivo.']); exit; }

    // Crear carpeta temporal
    $extractPath = sys_get_temp_dir() . '/restore_' . uniqid();
    if (!mkdir($extractPath)) { echo json_encode(['success' => false, 'message' => 'Error creando dir temporal']); exit; }

    try {
        $zip = new ZipArchive;
        if ($zip->open($file['tmp_name']) === TRUE) {
            $zip->setPassword('*bckp!00*'); // Contraseña hardcoded
            $zip->extractTo($extractPath);
            $zip->close();
            
            // Buscar el .sql extraído
            $sqlFile = null;
            $files = scandir($extractPath);
            foreach($files as $f) { if(pathinfo($f, PATHINFO_EXTENSION) === 'sql') { $sqlFile = $extractPath . '/' . $f; break; } }

            if ($sqlFile && file_exists($sqlFile)) {
                // Leer y ejecutar SQL
                $sqlContent = file_get_contents($sqlFile);
                // Limpiar tablas actuales antes de restaurar? 
                // El backup generado ya tiene DROP TABLE IF EXISTS implicito en el dump completo no? 
                // No, el generador actual usa SHOW CREATE TABLE. Debemos vaciar o confiar en que el script maneja conflictos.
                // Mejor: Agregamos DROP TABLE dinámico a la base de datos actual para asegurar restauración limpia.
                
                $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $table) { $pdo->exec("DROP TABLE IF EXISTS `$table`"); }
                
                // Ejecutar restauración
                $pdo->exec($sqlContent);
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No se encontró archivo .sql válido dentro del ZIP o contraseña incorrecta.']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No se pudo abrir el archivo ZIP.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error SQL al restaurar: ' . $e->getMessage()]);
    } finally {
        // Limpieza
        array_map('unlink', glob("$extractPath/*.*")); rmdir($extractPath);
    }
    exit;
}

// --- CONTROLADOR JSON API ---
try {
    if ($action === 'login') {
        $usuario = $input['user'] ?? ''; $passInput = $input['pass'] ?? '';
        $stmtUser = $pdo->prepare("SELECT * FROM usuarios WHERE user = ?"); $stmtUser->execute([$usuario]); $userData = $stmtUser->fetch();
        if ($userData && (password_verify($passInput, $userData['pass']) || $userData['pass'] === $passInput)) {
            if ($userData['pass'] === $passInput) $pdo->prepare("UPDATE usuarios SET pass = ? WHERE id = ?")->execute([password_hash($passInput, PASSWORD_DEFAULT), $userData['id']]);
            $_SESSION['user_id'] = $userData['id']; $_SESSION['user_name'] = $userData['user']; $_SESSION['is_admin'] = ($userData['rol'] === 'admin');
            echo json_encode(['success' => true, 'user' => ['user' => $userData['user'], 'admin' => $_SESSION['is_admin']]]);
        } else echo json_encode(['success' => false, 'message' => 'Credenciales incorrectas']); exit;
    }

    if (!isset($_SESSION['user_id'])) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Sesión expirada']); exit; }

    if ($action === 'getAll') {
        $data = [];
        $data['historial'] = $pdo->query("SELECT *, DATE_FORMAT(fecha, '%d/%m/%Y %H:%i') as fecha_fmt FROM historial ORDER BY id DESC LIMIT 50")->fetchAll();
        $data['personal'] = $pdo->query("SELECT nombre FROM personal ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);
        $data['oficinas'] = $pdo->query("SELECT nombre FROM oficinas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);
        $data['usuarios'] = $pdo->query("SELECT user, admin, rol FROM usuarios")->fetchAll(); foreach($data['usuarios'] as &$u) $u['admin'] = (bool)$u['admin'];
        $data['devoluciones'] = $pdo->query("SELECT *, DATE_FORMAT(fecha, '%d/%m/%Y') as fecha_fmt FROM devoluciones ORDER BY id DESC LIMIT 200")->fetchAll();
        $configs = $pdo->query("SELECT * FROM configuracion")->fetchAll(); $confOut = []; foreach($configs as $c) $confOut[$c['clave']] = json_decode($c['valor']); $data['config'] = $confOut;
        $data['categorias_resumen'] = $pdo->query("SELECT categoria, oficina, COUNT(*) as total, SUM(CASE WHEN estado='Disponible' THEN 1 ELSE 0 END) as disponibles FROM inventario WHERE estado!='Devuelto' GROUP BY categoria, oficina")->fetchAll();
        $data['grafica_tecnicos'] = $pdo->query("SELECT persona, COUNT(*) as total FROM inventario WHERE persona IS NOT NULL AND fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY persona")->fetchAll();
        echo json_encode($data); exit;
    }

    if ($action === 'getInventarioPaginado') {
        $pagina = (int)($input['pagina'] ?? 1); 
        $limite = 50; 
        $offset = ($pagina - 1) * $limite; 
        $filtro = $input['filtro'] ?? 'unassigned';
        
        $sql = "SELECT * FROM inventario WHERE 1=1"; 
        $params = [];
        
        // MODIFICADO: Lógica de filtros actualizada
        if ($filtro === 'unassigned') $sql .= " AND persona IS NULL AND estado != 'Devuelto'";
        if ($filtro === 'assigned') $sql .= " AND persona IS NOT NULL";
        if ($filtro === 'returned') $sql .= " AND estado = 'Devuelto'"; // Nuevo filtro
        
        if (($input['categoria']??'total') !== 'total') { $sql .= " AND categoria = ?"; $params[] = $input['categoria']; }
        if (($input['oficina']??'total') !== 'total') { $sql .= " AND oficina = ?"; $params[] = $input['oficina']; }
        
        $rf = $input['fecha_filtro'] ?? 'total';
        if ($rf === 'today') $sql .= " AND DATE(fecha) = CURDATE()"; 
        elseif ($rf !== 'total') { 
            $sql .= " AND fecha >= DATE_SUB(NOW(), INTERVAL ? DAY)"; 
            $params[] = ($rf == '7d') ? 7 : (($rf == '30d') ? 30 : 180); 
        }
        
        if (!empty($input['busqueda'])) { 
            $sql .= " AND (codigo LIKE ? OR nombre LIKE ? OR persona LIKE ? OR cliente LIKE ?)"; 
            $b = "%".$input['busqueda']."%"; 
            array_push($params, $b, $b, $b, $b); 
        }
        
        $stmtCount = $pdo->prepare(str_replace("SELECT *", "SELECT COUNT(*)", $sql)); 
        $stmtCount->execute($params); 
        $totalItems = $stmtCount->fetchColumn();
        
        $sql .= " ORDER BY id DESC LIMIT $limite OFFSET $offset"; 
        $stmt = $pdo->prepare($sql); 
        $stmt->execute($params);
        
        echo json_encode(['items' => $stmt->fetchAll(), 'paginas' => ceil($totalItems / $limite), 'pagina_actual' => $pagina, 'total_items' => $totalItems]); 
        exit;
    }

    if ($action === 'registrarArticulo') {
        $sql = "INSERT INTO inventario (codigo, nombre, serial, categoria, estado, fecha, usuario, oficina) VALUES (?, ?, ?, ?, 'Disponible', NOW(), ?, ?)"; $stmt = $pdo->prepare($sql);
        foreach ($input['items'] as $item) { $stmt->execute([$item['codigo'], $item['nombre'], $item['serial'], $item['categoria'], $_SESSION['user_name'], $input['oficina'] ?? null]); }
        $pdo->prepare("INSERT INTO historial (fecha, accion, codigo, nombre_articulo, persona, tecnico) VALUES (NOW(), 'Registro', ?, ?, ?, ?)")->execute(["(".$input['cantidad'].")", $input['nombre'], "Cat: ".$input['categoria'], $_SESSION['user_name']]);
        echo json_encode(['success' => true]); exit;
    }
   if ($action === 'asignarArticulo') {
        $codigos = $input['codigos']; // Ahora esperamos un array
        $persona = $input['persona'];
        $usuario = $_SESSION['user_name'];
        $errores = 0;

        foreach ($codigos as $codigo) {
            // Buscamos datos del artículo individualmente
            $stmtData = $pdo->prepare("SELECT nombre, categoria, oficina FROM inventario WHERE codigo = ?"); 
            $stmtData->execute([$codigo]); 
            $itemData = $stmtData->fetch();

            if ($itemData) {
                // Actualizamos: Sin cliente, Uso automático 'Stock Técnico'
                $pdo->prepare("UPDATE inventario SET persona=?, uso='Stock Técnico', cliente=NULL, estado='Asignado', fecha=NOW(), usuario=? WHERE codigo=?")
                    ->execute([$persona, $usuario, $codigo]);
                
                // Historial
                $pdo->prepare("INSERT INTO historial (fecha, accion, codigo, nombre_articulo, persona, tecnico) VALUES (NOW(), 'Asignación', ?, ?, ?, ?)")
                    ->execute([$codigo, $itemData['nombre'], $persona, $usuario]);
                
                // Verificamos alerta de stock
                verificarStockYAlertar($pdo, $itemData['categoria'], $itemData['oficina']);
            } else {
                $errores++; // Código no encontrado
            }
        }

        if ($errores == count($codigos)) {
            echo json_encode(['success' => false, 'message' => 'Ningún código encontrado']);
        } else {
            echo json_encode(['success' => true]); 
        }
        exit;
    }
    if ($action === 'devolverArticulo') {
        $codigo = $input['codigo'];
        $nombre = $input['nombre'];
        $cat = $input['categoria'];
        $razon = $input['razon'];
        $falla = $input['tipoFalla'] ?? '';
        $cliente = $input['clienteOLugar'];
        $tecnico = !empty($input['tecnico']) ? $input['tecnico'] : null;
        $oficina = $input['oficina'];
        $usuario = $_SESSION['user_name'];
        $usoTexto = "Devuelto: " . $razon;

        // 1. Guardar en Historial de Devoluciones
        $pdo->prepare("INSERT INTO devoluciones (fecha, codigo, nombre, categoria, razon, tipoFalla, clienteOLugar, asignadoA, tecnico, reutilizado) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 0)")
            ->execute([$codigo, $nombre, $cat, $razon, $falla, $cliente, ($tecnico ? $tecnico : 'Sin Tecnico'), $usuario]);

        // 2. Actualizar Inventario General con la nueva ubicación y técnico
        $check = $pdo->prepare("SELECT id FROM inventario WHERE codigo = ?");
        $check->execute([$codigo]);
        
        if ($check->rowCount() > 0) {
            // Actualizamos: Técnico en 'persona', Oficina en 'oficina', Razón en 'uso'
            $sqlUpd = "UPDATE inventario SET persona=?, oficina=?, cliente=?, uso=?, estado='Devuelto', fecha=NOW(), usuario=? WHERE codigo=?";
            $pdo->prepare($sqlUpd)->execute([$tecnico, $oficina, $cliente, $usoTexto, $usuario, $codigo]);
        } else {
            // Caso raro: Crear si no existe
            $sqlIns = "INSERT INTO inventario (codigo, nombre, categoria, estado, fecha, usuario, uso, oficina, persona, cliente) VALUES (?, ?, ?, 'Devuelto', NOW(), ?, ?, ?, ?, ?)";
            $pdo->prepare($sqlIns)->execute([$codigo, $nombre, $cat, $usuario, $usoTexto, $oficina, $tecnico, $cliente]);
        }

        // 3. Registrar en Historial
        $pdo->prepare("INSERT INTO historial (fecha, accion, codigo, nombre_articulo, persona, tecnico) VALUES (NOW(), 'Devolución', ?, ?, ?, ?)")
            ->execute([$codigo, $nombre, "Cliente: $cliente / Ofi: $oficina", $usuario]);

        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'reutilizarArticulo') {
        // MODIFICADO: Ahora recibe la oficina y actualiza el estado
        $oficinaDestino = $input['oficina'] ?? null;
        
        $pdo->prepare("UPDATE inventario SET persona=NULL, cliente=NULL, uso='Reingreso', estado='Disponible', oficina=?, fecha=NOW(), usuario=? WHERE codigo=?")->execute([$oficinaDestino, $_SESSION['user_name'], $input['codigo']]);
        
        $pdo->prepare("UPDATE devoluciones SET reutilizado=1 WHERE codigo=?")->execute([$input['codigo']]);
        
        $nombreOficina = $oficinaDestino ? "Oficina: $oficinaDestino" : "Oficina: General";
        
        $pdo->prepare("INSERT INTO historial (fecha, accion, codigo, nombre_articulo, persona, tecnico) VALUES (NOW(), 'Reingreso', ?, ?, ?, ?)")->execute([$input['codigo'], $input['nombre'], "$nombreOficina", $_SESSION['user_name']]); 
        
        // Verificar stock en la oficina donde ingresó
        verificarStockYAlertar($pdo, $input['categoria'] ?? 'General', $oficinaDestino);
        
        echo json_encode(['success' => true]); exit;
    }
    if ($action === 'moverArticuloOficina') {
        $stmt = $pdo->prepare("SELECT nombre, oficina, categoria FROM inventario WHERE codigo = ?"); $stmt->execute([$input['codigo']]); $art = $stmt->fetch();
        if ($art) {
            $pdo->prepare("UPDATE inventario SET oficina = ? WHERE codigo = ?")->execute([$input['nueva_oficina'], $input['codigo']]);
            $pdo->prepare("INSERT INTO historial (fecha, accion, codigo, nombre_articulo, persona, tecnico) VALUES (NOW(), 'Transferencia', ?, ?, ?, ?)")->execute([$input['codigo'], $art['nombre'], "De: ".($art['oficina']?:"General")." -> A: ".$input['nueva_oficina'], $_SESSION['user_name']]);
            verificarStockYAlertar($pdo, $art['categoria'], $input['nueva_oficina']); echo json_encode(['success' => true]);
        } else echo json_encode(['success' => false]); exit;
    }
    if ($action === 'eliminarArticulo') {
        $pdo->prepare("DELETE FROM inventario WHERE codigo=?")->execute([$input['codigo']]);
        $pdo->prepare("INSERT INTO historial (fecha, accion, codigo, nombre_articulo, persona, tecnico) VALUES (NOW(), 'Eliminación', ?, ?, 'Manual', ?)")->execute([$input['codigo'], 'Articulo Eliminado', $_SESSION['user_name']]); echo json_encode(['success' => true]); exit;
    }
    if ($action === 'guardarConfig') { $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([$input['clave'], json_encode($input['valor']), json_encode($input['valor'])]); echo json_encode(['success' => true]); exit; }
    if ($action === 'gestionPersonal' || $action === 'gestionOficinas') { $tbl = ($action === 'gestionPersonal') ? 'personal' : 'oficinas'; if ($input['subAction'] == 'add') $pdo->prepare("INSERT INTO $tbl (nombre) VALUES (?)")->execute([$input['nombre']]); elseif ($input['subAction'] == 'del') $pdo->prepare("DELETE FROM $tbl WHERE nombre = ?")->execute([$input['nombre']]); echo json_encode(['success' => true]); exit; }
    if ($action === 'gestionUsuarios' && $_SESSION['is_admin']) { if ($input['subAction'] == 'add') $pdo->prepare("INSERT INTO usuarios (user, pass, admin, rol) VALUES (?, ?, ?, ?)")->execute([$input['user'], password_hash($input['pass'], PASSWORD_DEFAULT), $input['admin']?1:0, $input['admin']?'admin':'user']); elseif ($input['subAction'] == 'del') $pdo->prepare("DELETE FROM usuarios WHERE user = ?")->execute([$input['user']]); elseif ($input['subAction'] == 'changePass') $pdo->prepare("UPDATE usuarios SET pass=? WHERE user=?")->execute([password_hash($input['pass'], PASSWORD_DEFAULT), $input['user']]); elseif ($input['subAction'] == 'promote') $pdo->prepare("UPDATE usuarios SET admin=1, rol='admin' WHERE user=?")->execute([$input['user']]); echo json_encode(['success' => true]); exit; }
    if ($action === 'getArticulosPorTecnico') { $sql = "SELECT * FROM inventario WHERE persona = ?"; $p = [$input['tecnico']]; if(($input['rango']??'total') === 'today') $sql .= " AND DATE(fecha) = CURDATE()"; elseif(($input['rango']??'total') !== 'total') { $sql .= " AND fecha >= DATE_SUB(NOW(), INTERVAL ? DAY)"; $p[] = ($input['rango']=='7d')?7:(($input['rango']=='30d')?30:180); } $stmt = $pdo->prepare($sql); $stmt->execute($p); echo json_encode($stmt->fetchAll()); exit; }
    if ($action === 'eliminarDevolucion') { $pdo->prepare("DELETE FROM devoluciones WHERE codigo=?")->execute([$input['codigo']]); echo json_encode(['success' => true]); exit; }

    if ($action === 'testSMTP') { 
        $res = enviarCorreoSeguro($input['config'], "Test de Correo", "<h1>Correo de prueba exitoso</h1><p>El sistema está configurado correctamente.</p>");
        if($res['ok']) echo json_encode(['success' => true]); else echo json_encode(['success' => false, 'message' => "Error: " . $res['error']]); exit; 
    }

    if ($action === 'guardarConfigBackup') {
        $datos = [
            'host' => $input['host'], 'port' => $input['port'], 'user' => $input['user'], 'pass' => $input['pass'],
            'tls' => $input['tls'], 'from' => $input['from'], 'receiver' => $input['receiver'], 
            'frecuencia' => $input['frecuencia'], 'hora' => $input['hora'], 'ultimo_backup' => $input['ultimo_backup'] ?? null
        ];
        $pdo->prepare("INSERT INTO configuracion (clave, valor) VALUES ('backupConfig', ?) ON DUPLICATE KEY UPDATE valor = ?")->execute([json_encode($datos), json_encode($datos)]); echo json_encode(['success' => true]); exit;
    }

    if ($action === 'checkAutoBackup') {
        $stmtConf = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'backupConfig'"); $stmtConf->execute(); $res = $stmtConf->fetch();
        if ($res) {
            $conf = json_decode($res['valor'], true);
            if (isset($conf['frecuencia']) && isset($conf['hora']) && !empty($conf['host'])) {
                $lastRun = $conf['ultimo_backup'] ?? 0; $frecuenciaHoras = intval($conf['frecuencia']); $horasPasadas = (time() - $lastRun) / 3600;
                $horaActual = intval(date('H')); $horaTarget = intval(explode(':', $conf['hora'])[0]);
                if (($horasPasadas >= $frecuenciaHoras && $horaActual >= $horaTarget) || $lastRun == 0) { echo json_encode(['run' => true]); exit; }
            }
        }
        echo json_encode(['run' => false]); exit;
    }

    if ($action === 'ejecutarBackup') {
        $stmtConf = $pdo->prepare("SELECT valor FROM configuracion WHERE clave = 'backupConfig'"); $stmtConf->execute();
        $conf = json_decode($stmtConf->fetch()['valor'], true);
        global $mailAvailable;
        if (!$mailAvailable) { echo json_encode(['success' => false, 'message' => 'Falta carpeta PHPMailer']); exit; }
        if (empty($conf['host']) || empty($conf['receiver'])) { echo json_encode(['success' => false, 'message' => 'Configuración SMTP incompleta']); exit; }
        if (!class_exists('ZipArchive')) { echo json_encode(['success' => false, 'message' => 'ERROR CRÍTICO: ZIP no instalado.']); exit; }

        try {
            $sqlContent = generarBackupSQL($pdo, $db); $fecha = date('Y-m-d_H-i');
            $nombreZip = "Backup-Inventario-Isp-$fecha.zip"; $nombreSqlInterno = "backup_isp_$fecha.sql";
            $tempFile = tempnam(sys_get_temp_dir(), 'bck_');
            $zip = new ZipArchive();
            if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) { echo json_encode(['success' => false, 'message' => 'Error al crear ZIP.']); exit; }
            $zip->addFromString($nombreSqlInterno, $sqlContent);
            $password = '*bckp!00*';
            if (method_exists($zip, 'setEncryptionName')) { $zip->setEncryptionName($nombreSqlInterno, ZipArchive::EM_AES_256, $password); } 
            elseif(method_exists($zip, 'setPassword')) { $zip->setPassword($password); }
            $zip->close();

            if (!file_exists($tempFile)) { echo json_encode(['success' => false, 'message' => 'Error ZIP fallido.']); exit; }
            $zipContent = file_get_contents($tempFile); unlink($tempFile);
            $mensajeBody = "<h3>Copia de seguridad automática</h3><p>Adjunto encontrarás el respaldo protegido.</p>";
            $resultado = enviarCorreoSeguro($conf, "📦 Backup Protegido: $fecha", $mensajeBody, $zipContent, $nombreZip);

            if ($resultado['ok']) {
                $conf['ultimo_backup'] = time();
                $pdo->prepare("UPDATE configuracion SET valor = ? WHERE clave = 'backupConfig'")->execute([json_encode($conf)]);
                echo json_encode(['success' => true]);
            } else { echo json_encode(['success' => false, 'message' => "SMTP Error: " . $resultado['error']]); }
        } catch (Exception $e) { echo json_encode(['success' => false, 'message' => 'Error interno: ' . $e->getMessage()]); }
        exit;
    }
    if ($action === 'completarOrden') {
        $codigo = $input['codigo'];
        $cliente = $input['cliente'];
        $uso = $input['uso']; // Razón: Instalación, Avería, etc.
        $usuario = $_SESSION['user_name'];

        // Verificar si el articulo existe
        $check = $pdo->prepare("SELECT id, nombre, persona FROM inventario WHERE codigo = ?");
        $check->execute([$codigo]);
        $art = $check->fetch();

        if ($art) {
            // Actualizamos SOLO cliente y uso (razon). Mantenemos el técnico (persona) que ya lo tenía.
            // Tambien actualizamos la fecha para saber cuando se instaló.
            $sql = "UPDATE inventario SET cliente = ?, uso = ?, fecha = NOW(), usuario = ? WHERE codigo = ?";
            $pdo->prepare($sql)->execute([$cliente, $uso, $usuario, $codigo]);

            // Historial
            $pdo->prepare("INSERT INTO historial (fecha, accion, codigo, nombre_articulo, persona, tecnico) VALUES (NOW(), 'Instalación', ?, ?, ?, ?)")
                ->execute([$codigo, $art['nombre'], "Cliente: $cliente ($uso)", $usuario]);

            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Código no encontrado en inventario.']);
        }
        exit;
    }
} catch (Exception $e) { echo json_encode(['success' => false, 'message' => 'Server Error']); }
?>