<?php

$startTime = microtime(true);

// Configuración de zona horaria y lectura de CSV (Linux/Windows/Mac)
date_default_timezone_set('Europe/Madrid');
ini_set('auto_detect_line_endings', true);

// Base URLs de los datos
$baseUrl  = "https://flydecision.com/";
$urlMeteo = $baseUrl . "meteo-datos.json";
$urlIcon  = $baseUrl . "estudio_meteo-datos-icon.json";
$urlEcmwf = $baseUrl . "meteo-datos-ecmwf.json";

// Archivos locales
$fileMapeo = __DIR__ . '/estudio_pares_despegues_balizas.csv';
$fileLog   = __DIR__ . '/estudio.csv';

// 1. Cargar mapeo desde CSV
if (!file_exists($fileMapeo)) {
    die("Error crítico: No se encuentra el archivo de mapeo $fileMapeo\n");
}

$mapeos = [];
if (($handle = fopen($fileMapeo, "r")) !== FALSE) {
    $rawHeader = fgetcsv($handle, 1000, ";");
    
    if ($rawHeader !== false) {
        $header = array_map(function($col) {
            return trim(preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/', '', $col));
        }, $rawHeader);

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
            if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) continue;
            $data = array_map('trim', $data);

            while (count($data) > count($header) && end($data) === '') {
                array_pop($data);
            }

            if (count($header) === count($data)) {
                $mapeos[] = array_combine($header, $data);
            }
        }
    }
    fclose($handle);
}

if (empty($mapeos)) {
    die("Error: No se pudo cargar ninguna fila de $fileMapeo.\n");
}

// Función auxiliar para descargas HTTP seguras con Timeout y control de errores HTTP (404/500)
function downloadUrlWithTimeout($url, $timeoutSeconds = 8) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FAILONERROR, true); // Devuelve false si el servidor responde con 4xx o 5xx
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($httpCode >= 200 && $httpCode < 300) ? $result : false;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeoutSeconds, 'ignore_errors' => false]]);
    return @file_get_contents($url, false, $ctx);
}

// 2. Obtener datos JSON de pronósticos (Arome/ICON y ECMWF) — solo lectura local.
// Los genera el cron correspondiente en este mismo servidor; si no están en
// disco es que ese cron no ha corrido o falló, y pedirlos por HTTP a
// flydecision.com solo añade un segundo proceso PHP colgado hasta 10s
// esperando a que el propio servidor se responda a sí mismo.
function obtenerJsonMeteoLocal($nombreArchivo) {
    // estudio.php vive en /estudio, pero meteo-datos.json, estudio_meteo-datos-icon.json
    // y meteo-datos-ecmwf.json se generan en la raíz del sitio (un nivel por encima) —
    // por eso apuntamos a dirname(__DIR__), no a __DIR__ directamente.
    $rutaLocal = dirname(__DIR__) . '/' . $nombreArchivo;
    if (!file_exists($rutaLocal)) return null;
    $raw = @file_get_contents($rutaLocal);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return $data ?: null;
}

$dataMeteo = obtenerJsonMeteoLocal('meteo-datos.json');
$dataIcon  = obtenerJsonMeteoLocal('estudio_meteo-datos-icon.json');
$dataEcmwf = obtenerJsonMeteoLocal('meteo-datos-ecmwf.json');

if (!$dataMeteo) {
    $rutaDiag = __DIR__ . '/meteo-datos.json';
    $existe = file_exists($rutaDiag);
    $tam = $existe ? filesize($rutaDiag) : null;
    $mtime = $existe ? date('Y-m-d H:i:s', filemtime($rutaDiag)) : null;
    " | __DIR__=" . __DIR__ .
    " | contenido_carpeta=" . $contenidoCarpeta .
    $inicio = $existe ? substr(@file_get_contents($rutaDiag, false, null, 0, 200), 0, 200) : null;
    $contenidoCarpeta = implode(', ', array_diff(scandir(__DIR__), ['.', '..']));
    die("Error: No se pudieron cargar los pronósticos de meteo-datos.json.\n" .
        "Diagnóstico: existe=" . ($existe ? 'si' : 'no') .
        " | tamaño=" . ($tam ?? 'n/a') .
        " | mtime=" . ($mtime ?? 'n/a') .
        " | memory_limit=" . ini_get('memory_limit') .
        " | memoria_usada_pico=" . round(memory_get_peak_usage(true) / 1024 / 1024, 1) . "MB" .
        " | primeros200chars=" . var_export($inicio, true) . "\n");
}

// Extrae el valor del campo ID de un despegue, sin importar mayúsculas/minúsculas
// ni caracteres invisibles (BOM UTF-8, etc.) que puedan venir pegados a la clave.
function extraerIdDespegue($despegue) {
    foreach ($despegue as $key => $value) {
        $cleanKey = trim(preg_replace('/[\x00-\x1F\x7F\xEF\xBB\xBF]/', '', $key));
        if (strcasecmp($cleanKey, 'id') === 0) {
            return (string)$value;
        }
    }
    return null;
}

// 3. Crear índices de búsqueda rápida de despegues
$despegueIndexMap = [];
if (isset($dataMeteo['despegues'])) {
    foreach ($dataMeteo['despegues'] as $index => $despegue) {
        $id = extraerIdDespegue($despegue);
        if ($id !== null) {
            $despegueIndexMap[$id] = $index;
        }
    }
}

$iconIndexMap = [];
if (isset($dataIcon['despegues'])) {
    foreach ($dataIcon['despegues'] as $index => $despegue) {
        $id = extraerIdDespegue($despegue);
        if ($id !== null) {
            $iconIndexMap[$id] = $index;
        }
    }
}

$ecmwfIndexMap = [];
if (isset($dataEcmwf['despegues'])) {
    foreach ($dataEcmwf['despegues'] as $index => $despegue) {
        $id = extraerIdDespegue($despegue);
        if ($id !== null) {
            $ecmwfIndexMap[$id] = $index;
        }
    }
}

// 4. Determinar marcas de tiempo objetivo (Restamos 1 hora para alinear con MeteoCat)
$nowTs = time() - 3600; // 3600 segundos = 1 hora
$ts6h  = $nowTs + (6 * 3600);
$ts24h = $nowTs + (24 * 3600);
$ts48h = $nowTs + (48 * 3600);
$ts72h = $nowTs + (72 * 3600);

$fechaCaptura = date('Y-m-d H:i:s', $nowTs);
$cacheBalizasJSON = [];

// =========================================================================
// 5. FUNCIONES MATEMÁTICAS Y AUXILIARES
// =========================================================================

// Lógica de interpolación de viento vectorial ECMWF según altitud del despegue
function interpolarVientoAltitudReal($H_target, $h1000, $h925, $h850, $h700, $v1000, $v925, $v850, $v700, $d1000, $d925, $d850, $d700) {
    $heights    = [$h1000, $h925, $h850, $h700];
    $speeds     = [$v1000, $v925, $v850, $v700];
    $directions = [$d1000, $d925, $d850, $d700];

    $points = [];
    for ($k = 0; $k < 4; $k++) {
        $z = $heights[$k];
        $v = $speeds[$k];
        $d = $directions[$k];
        if ($z !== null && $v !== null && $d !== null) {
            $rad = floatval($d) * M_PI / 180.0;
            $u = floatval($v) * sin($rad);
            $v_comp = floatval($v) * cos($rad);
            $points[] = ['z' => floatval($z), 'u' => $u, 'v_comp' => $v_comp];
        }
    }

    if (empty($points)) return null;
    if (count($points) === 1) {
        $speed = sqrt($points[0]['u'] * $points[0]['u'] + $points[0]['v_comp'] * $points[0]['v_comp']);
        $dir = fmod(atan2($points[0]['u'], $points[0]['v_comp']) * 180.0 / M_PI + 360.0, 360.0);
        return ['speed' => $speed, 'dir' => $dir];
    }

    usort($points, function($a, $b) {
        return $a['z'] <=> $b['z'];
    });

    $u_interp = null;
    $v_interp = null;

    $interp = function($h, $p1, $p2, $key) {
        return $p1[$key] + (($h - $p1['z']) / ($p2['z'] - $p1['z'])) * ($p2[$key] - $p1[$key]);
    };

    $bracketFound = false;
    for ($j = 0; $j < count($points) - 1; $j++) {
        $p1 = $points[$j];
        $p2 = $points[$j+1];
        if ($H_target >= $p1['z'] && $H_target <= $p2['z']) {
            $u_interp = $interp($H_target, $p1, $p2, 'u');
            $v_interp = $interp($H_target, $p1, $p2, 'v_comp');
            $bracketFound = true;
            break;
        }
    }

    if (!$bracketFound) {
        if ($H_target < $points[0]['z']) {
            $u_interp = $interp($H_target, $points[0], $points[1], 'u');
            $v_interp = $interp($H_target, $points[0], $points[1], 'v_comp');
        } else {
            $lastIdx = count($points) - 1;
            $u_interp = $interp($H_target, $points[$lastIdx - 1], $points[$lastIdx], 'u');
            $v_interp = $interp($H_target, $points[$lastIdx - 1], $points[$lastIdx], 'v_comp');
        }
    }

    $speed = sqrt($u_interp * $u_interp + $v_interp * $v_interp);
    $dir   = fmod(atan2($u_interp, $v_interp) * 180.0 / M_PI + 360.0, 360.0);
    return ['speed' => $speed, 'dir' => $dir];
}

function getClosestRealData($stationData, $targetTs, $maxDiffSeconds = 2700) {
    // maxDiffSeconds = 2700 segundos (45 minutos de margen máximo)
    if (empty($stationData)) return null;
    $closest = null;
    $minDiff = null;

    foreach ($stationData as $entry) {
        $entryTs = isset($entry['ts']) ? $entry['ts'] : strtotime(($entry['date'] ?? '') . ' ' . ($entry['time'] ?? ''));
        if (!$entryTs) continue;

        $diff = abs($targetTs - $entryTs);

        if ($minDiff === null || $diff < $minDiff) {
            $minDiff = $diff;
            $closest = $entry;
            $closest['ts_real'] = $entryTs;
        }
    }

    // 🛡️ FILTRO DE SEGURIDAD: Si la medida dista más de 45 minutos, se descarta por obsoleta/baliza caída
    if ($minDiff !== null && $minDiff > $maxDiffSeconds) {
        return null;
    }

    return $closest;
}

function getClosestForecastData($hourlyData, $targetTs) {
    if (empty($hourlyData) || empty($hourlyData['time'])) return null;
    $closestIndex = null;
    $minDiff = null;
    $closestTs = null;

    foreach ($hourlyData['time'] as $index => $timeStr) {
        // Las horas de Open-Meteo vienen en UTC
        $entryTs = strtotime($timeStr . ' UTC');
        $diff = abs($targetTs - $entryTs);

        if ($minDiff === null || $diff < $minDiff) {
            $minDiff = $diff;
            $closestIndex = $index;
            $closestTs = $entryTs;
        }
    }

    if ($closestIndex !== null) {
        return [
            // Formateamos el timestamp a la zona horaria local (Europe/Madrid)
            'fecha' => date('Y-m-d H:i', $closestTs),
            'speed' => $hourlyData['wind_speed_10m'][$closestIndex] ?? null,
            'gusts' => $hourlyData['wind_gusts_10m'][$closestIndex] ?? null,
            'dir'   => $hourlyData['wind_direction_10m'][$closestIndex] ?? null
        ];
    }
    return null;
}

function getClosestForecastDataStrict($hourlyData, $targetTs, $modeloRequerido) {
    if (empty($hourlyData) || empty($hourlyData['time'])) return null;
    $closestIndex = null;
    $minDiff = null;
    $closestTs = null;

    foreach ($hourlyData['time'] as $index => $timeStr) {
        $entryTs = strtotime($timeStr . ' UTC');
        $diff = abs($targetTs - $entryTs);

        if ($minDiff === null || $diff < $minDiff) {
            $minDiff = $diff;
            $closestIndex = $index;
            $closestTs = $entryTs;
        }
    }

    if ($closestIndex !== null) {
        // Comprobar si el modelo en esta hora coincide estrictamente con el pedido (ej. 'AromeHD')
        $modeloEnHora = $hourlyData['model_source'][$closestIndex] ?? 'ICON-EU';
        if ($modeloEnHora !== $modeloRequerido) {
            return null; // Si a +48h o +72h ya no es Arome, devolvemos vacío para no duplicar ICON
        }

        return [
            'fecha'  => date('Y-m-d H:i', $closestTs),
            'modelo' => $modeloEnHora,
            'speed'  => $hourlyData['wind_speed_10m'][$closestIndex] ?? null,
            'gusts'  => $hourlyData['wind_gusts_10m'][$closestIndex] ?? null,
            'dir'    => $hourlyData['wind_direction_10m'][$closestIndex] ?? null
        ];
    }
    return null;
}

function getClosestForecastDataEcmwf($hourlyEcmwf, $targetTs, $altitudDespegue) {
    if (empty($hourlyEcmwf) || empty($hourlyEcmwf['time'])) return null;
    $closestIndex = null;
    $minDiff = null;
    $closestTs = null;

    foreach ($hourlyEcmwf['time'] as $index => $timeStr) {
        // Mismo motivo que en getClosestForecastData(): horas en UTC
        $entryTs = strtotime($timeStr . ' UTC');
        $diff = abs($targetTs - $entryTs);

        if ($minDiff === null || $diff < $minDiff) {
            $minDiff = $diff;
            $closestIndex = $index;
            $closestTs = $entryTs;
        }
    }

    if ($closestIndex !== null) {
        $res = interpolarVientoAltitudReal(
            $altitudDespegue,
            $hourlyEcmwf['geopotential_height_1000hPa'][$closestIndex] ?? null,
            $hourlyEcmwf['geopotential_height_925hPa'][$closestIndex]  ?? null,
            $hourlyEcmwf['geopotential_height_850hPa'][$closestIndex]  ?? null,
            $hourlyEcmwf['geopotential_height_700hPa'][$closestIndex]  ?? null,
            $hourlyEcmwf['wind_speed_1000hPa'][$closestIndex]          ?? null,
            $hourlyEcmwf['wind_speed_925hPa'][$closestIndex]           ?? null,
            $hourlyEcmwf['wind_speed_850hPa'][$closestIndex]           ?? null,
            $hourlyEcmwf['wind_speed_700hPa'][$closestIndex]           ?? null,
            $hourlyEcmwf['wind_direction_1000hPa'][$closestIndex]      ?? null,
            $hourlyEcmwf['wind_direction_925hPa'][$closestIndex]       ?? null,
            $hourlyEcmwf['wind_direction_850hPa'][$closestIndex]       ?? null,
            $hourlyEcmwf['wind_direction_700hPa'][$closestIndex]       ?? null
        );

        if ($res !== null) {
            $gustRaw = $hourlyEcmwf['wind_gusts_10m'][$closestIndex] ?? null;
            return [
                // Formateamos el timestamp a la zona horaria local (Europe/Madrid)
                'fecha' => date('Y-m-d H:i', $closestTs),
                'speed' => round($res['speed']),
                'gusts' => $gustRaw !== null ? round($gustRaw) : null,
                'dir'   => round($res['dir'])
            ];
        }
    }
    return null;
}

// =========================================================================
// 6. PREPARAR ARCHIVO DE LOG, BLOQUEO FLOCK Y ESCUDO ANTI-DUPLICADOS
// =========================================================================

// Comprueba si existe Y si tiene algo de contenido (tamaño > 0)
$logExists = file_exists($fileLog) && filesize($fileLog) > 0;

$logHandle = @fopen($fileLog, 'a');

if (!$logHandle) {
    die("Error crítico: No se pudo abrir para escritura el archivo $fileLog.\n");
}

// 🔒 PREVENCIÓN DE CONCURRENCIA (flock): bloqueo exclusivo NO bloqueante.
// Si otra ejecución ya está escribiendo, salimos de inmediato en vez de
// esperar — eso era lo que apilaba procesos cuando una ejecución se
// alargaba más que el intervalo del cron.
if (!flock($logHandle, LOCK_EX | LOCK_NB)) {
    fclose($logHandle);
    die("Aviso: Ya hay otra ejecución de estudio.php en curso. Cancelando para evitar colapso.\n");
}

// Cargar capturas recientes para evitar duplicados en la misma hora
$capturasExistentes = [];
if ($logExists) {
    $handleRead = @fopen($fileLog, 'r');
    if ($handleRead) {
        // Leemos los últimos 100 KB para no sobrecargar la RAM
        $fSize = filesize($fileLog);
        if ($fSize > 100000) {
            fseek($handleRead, -$fSize > -100000 ? -$fSize : -100000, SEEK_END);
            fgets($handleRead); // Descartar primera línea por si quedó cortada
        }
        while (($rowLog = fgetcsv($handleRead, 2000, ';')) !== FALSE) {
            if (isset($rowLog[0], $rowLog[1]) && $rowLog[0] !== 'momento_captura') {
                $horaCapturaKey = substr(trim($rowLog[0], '"'), 0, 13); // Extrae "YYYY-MM-DD HH"
                $idDespKey      = trim($rowLog[1], '"');
                $capturasExistentes[$idDespKey . '_' . $horaCapturaKey] = true;
            }
        }
        fclose($handleRead);
    }
}

if (!$logExists) {
    fputcsv($logHandle, [
        'momento_captura',
        'id_despegue',
        'id_baliza',
        'despegue',
        'archivo_datos_balizas',
        // Realidad actual
        'real_momento', 'real_media', 'real_racha', 'real_direccion',
        // Pronóstico +24h (AromeHD, ICON-EU, ECMWF)
        '24h_pronostico_momento', '24h_arome_media', '24h_arome_racha', '24h_arome_direccion',
        '24h_icon_media', '24h_icon_racha', '24h_icon_direccion',
        '24h_ecmwf_media', '24h_ecmwf_direccion',
        // Pronóstico +48h (AromeHD, ICON-EU, ECMWF)
        '48h_pronostico_momento', '48h_arome_media', '48h_arome_racha', '48h_arome_direccion',
        '48h_icon_media', '48h_icon_racha', '48h_icon_direccion',
        '48h_ecmwf_media', '48h_ecmwf_direccion',
        // Pronóstico +72h (AromeHD, ICON-EU, ECMWF)
        '72h_pronostico_momento', '72h_arome_media', '72h_arome_racha', '72h_arome_direccion',
        '72h_icon_media', '72h_icon_racha', '72h_icon_direccion',
        '72h_ecmwf_media', '72h_ecmwf_direccion',
        // Pronóstico +6h (añadido después; va al final para no desalinear el CSV
        // histórico existente — el dashboard lee por nombre de columna, no por
        // posición, así que el orden aquí no afecta al análisis)
        '6h_pronostico_momento', '6h_arome_media', '6h_arome_racha', '6h_arome_direccion',
        '6h_icon_media', '6h_icon_racha', '6h_icon_direccion',
        '6h_ecmwf_media', '6h_ecmwf_direccion',
        // Racha ECMWF (añadida después; mismo motivo, va al final)
        '24h_ecmwf_racha', '48h_ecmwf_racha', '72h_ecmwf_racha', '6h_ecmwf_racha'
    ], ';');
}

// =========================================================================
// 7. PROCESAR CADA LUGAR MAPEADO
// =========================================================================

$filasEscritas = 0;
$filasOmitidas = 0;
$horaActualClave = substr($fechaCaptura, 0, 13); // Extrae "YYYY-MM-DD HH"

foreach ($mapeos as $map) {
    $idDespegue     = trim($map['id_despegue'] ?? '');
    $idBaliza       = trim($map['id_baliza'] ?? '');
    $nombre         = trim($map['nombre_lugar'] ?? '');
    $archivoBalizas = trim($map['archivo_datos_balizas'] ?? '');

    // 🛡️ CONTROL ANTI-DUPLICADOS: Si ya existe captura para este despegue en esta hora, omitir
    $claveDuplicado = $idDespegue . '_' . $horaActualClave;
    if (isset($capturasExistentes[$claveDuplicado])) {
        $filasOmitidas++;
        continue;
    }

    // Cargar JSON de balizas con Caché
    $dataBalizas = null;
    if (!empty($archivoBalizas)) {
        if (!isset($cacheBalizasJSON[$archivoBalizas])) {
            $rutaLocalBaliza = __DIR__ . '/' . $archivoBalizas;
            if (file_exists($rutaLocalBaliza)) {
                $jsonBalizaRaw = @file_get_contents($rutaLocalBaliza);
            } elseif (strpos($archivoBalizas, 'http') === 0 && strpos($archivoBalizas, 'flydecision.com') === false) {
                // Solo pedimos por HTTP si es una URL externa de verdad, no tu propio dominio
                $jsonBalizaRaw = downloadUrlWithTimeout($archivoBalizas, 8);
            } else {
                $jsonBalizaRaw = null; // fichero local esperado y ausente: no lo pidamos a tu propio servidor
            }
            $cacheBalizasJSON[$archivoBalizas] = $jsonBalizaRaw ? json_decode($jsonBalizaRaw, true) : null;
        }
        $dataBalizas = $cacheBalizasJSON[$archivoBalizas];
    }

    // --- Extraer Realidad ---
    // Tolerancia de frescura por proveedor: la mayoría de balizas (Holfuy, Pioupiou,
    // Euskalmet, MeteoNavarra...) publican casi en tiempo real, por eso 45 min basta.
    // Meteocat, al ser una red oficial con validación previa, publica con más retraso
    // estructural, así que le damos más margen solo a ella.
    $toleranciaPorArchivo = [
        'balizas_meteocat_6h.json' => 90 * 60, // 1h30 en vez de 45 min (Meteocat publica ~1h tarde)
    ];
    $maxDiffSeconds = $toleranciaPorArchivo[$archivoBalizas] ?? 2700;

    $realMatch = null;
    if ($dataBalizas && isset($dataBalizas[$idBaliza])) {
        $realMatch = getClosestRealData($dataBalizas[$idBaliza], $nowTs, $maxDiffSeconds);
    }

    // --- Extraer Pronósticos AromeHD Puros ---
    $p6 = $p24 = $p48 = $p72 = null;
    $altitudDespegue = 0;

    if (isset($despegueIndexMap[$idDespegue])) {
        $idx = $despegueIndexMap[$idDespegue];

        if (isset($dataMeteo['despegues'][$idx]['Altitud'])) {
            $altitudDespegue = floatval($dataMeteo['despegues'][$idx]['Altitud']);
        }

        if (isset($dataMeteo['respuestas'][$idx]['hourly'])) {
            $hourly = $dataMeteo['respuestas'][$idx]['hourly'];
            $p6  = getClosestForecastDataStrict($hourly, $ts6h, 'AromeHD');
            $p24 = getClosestForecastDataStrict($hourly, $ts24h, 'AromeHD');
            $p48 = getClosestForecastDataStrict($hourly, $ts48h, 'AromeHD');
            $p72 = getClosestForecastDataStrict($hourly, $ts72h, 'AromeHD');
        }
    }

    // --- Extraer Pronósticos ICON-EU Puros ---
    $icon6 = $icon24 = $icon48 = $icon72 = null;
    if ($dataIcon && isset($iconIndexMap[$idDespegue])) {
        $idxIcon = $iconIndexMap[$idDespegue];

        if (isset($dataIcon['respuestas'][$idxIcon]['hourly'])) {
            $hourlyIcon = $dataIcon['respuestas'][$idxIcon]['hourly'];
            $icon6  = getClosestForecastData($hourlyIcon, $ts6h);
            $icon24 = getClosestForecastData($hourlyIcon, $ts24h);
            $icon48 = getClosestForecastData($hourlyIcon, $ts48h);
            $icon72 = getClosestForecastData($hourlyIcon, $ts72h);
        }
    }

    // --- Extraer Pronósticos ECMWF (Interpolados a Altitud Real) ---
    $ec6 = $ec24 = $ec48 = $ec72 = null;
    if ($dataEcmwf && isset($ecmwfIndexMap[$idDespegue])) {
        $idxEcmwf = $ecmwfIndexMap[$idDespegue];

        if ($altitudDespegue == 0 && isset($dataEcmwf['despegues'][$idxEcmwf]['Altitud'])) {
            $altitudDespegue = floatval($dataEcmwf['despegues'][$idxEcmwf]['Altitud']);
        }

        if (isset($dataEcmwf['respuestas'][$idxEcmwf]['hourly'])) {
            $hourlyEcmwf = $dataEcmwf['respuestas'][$idxEcmwf]['hourly'];
            $ec6  = getClosestForecastDataEcmwf($hourlyEcmwf, $ts6h, $altitudDespegue);
            $ec24 = getClosestForecastDataEcmwf($hourlyEcmwf, $ts24h, $altitudDespegue);
            $ec48 = getClosestForecastDataEcmwf($hourlyEcmwf, $ts48h, $altitudDespegue);
            $ec72 = getClosestForecastDataEcmwf($hourlyEcmwf, $ts72h, $altitudDespegue);
        }
    }

    // --- Asegurar que siempre exista la fecha del pronóstico objetivo ---
    $momento6  = $p6['fecha']  ?? $icon6['fecha']  ?? $ec6['fecha']  ?? date('Y-m-d H:i', $ts6h);
    $momento24 = $p24['fecha'] ?? $icon24['fecha'] ?? $ec24['fecha'] ?? date('Y-m-d H:i', $ts24h);
    $momento48 = $p48['fecha'] ?? $icon48['fecha'] ?? $ec48['fecha'] ?? date('Y-m-d H:i', $ts48h);
    $momento72 = $p72['fecha'] ?? $icon72['fecha'] ?? $ec72['fecha'] ?? date('Y-m-d H:i', $ts72h);

    // --- Preparar fila de registro ---
    $row = [
        $fechaCaptura,
        $idDespegue,
        $idBaliza,
        $nombre,
        $archivoBalizas,
        // Realidad actual
        $realMatch ? date('Y-m-d H:i', $realMatch['ts_real']) : '',
        $realMatch['windSpeed'] ?? '',
        $realMatch['windGusts'] ?? '',
        $realMatch['windDirection'] ?? '',
        // +24h (AromeHD + ICON-EU + ECMWF)
        $momento24, $p24['speed'] ?? '', $p24['gusts'] ?? '', $p24['dir'] ?? '',
        $icon24['speed'] ?? '', $icon24['gusts'] ?? '', $icon24['dir'] ?? '',
        $ec24['speed'] ?? '', $ec24['dir'] ?? '',
        // +48h (AromeHD + ICON-EU + ECMWF)
        $momento48, $p48['speed'] ?? '', $p48['gusts'] ?? '', $p48['dir'] ?? '',
        $icon48['speed'] ?? '', $icon48['gusts'] ?? '', $icon48['dir'] ?? '',
        $ec48['speed'] ?? '', $ec48['dir'] ?? '',
        // +72h (AromeHD + ICON-EU + ECMWF)
        $momento72, $p72['speed'] ?? '', $p72['gusts'] ?? '', $p72['dir'] ?? '',
        $icon72['speed'] ?? '', $icon72['gusts'] ?? '', $icon72['dir'] ?? '',
        $ec72['speed'] ?? '', $ec72['dir'] ?? '',
        // +6h (AromeHD + ICON-EU + ECMWF)
        $momento6, $p6['speed'] ?? '', $p6['gusts'] ?? '', $p6['dir'] ?? '',
        $icon6['speed'] ?? '', $icon6['gusts'] ?? '', $icon6['dir'] ?? '',
        $ec6['speed'] ?? '', $ec6['dir'] ?? '',
        // Racha ECMWF (añadida después; va al final para no desalinear el CSV histórico)
        $ec24['gusts'] ?? '', $ec48['gusts'] ?? '', $ec72['gusts'] ?? '', $ec6['gusts'] ?? ''
    ];

    if (fputcsv($logHandle, $row, ';') !== FALSE) {
        $filasEscritas++;
    }
}

// 🔓 LIBERAR BLOQUEO Y CERRAR ARCHIVO
flock($logHandle, LOCK_UN);
fclose($logHandle);

// Calculamos el tiempo total que tardó el script
$executionTime = round(microtime(true) - $startTime, 2);

echo "$fechaCaptura | Read: " . count($mapeos) . " | Written: $filasEscritas | Omitted as duplicates: $filasOmitidas | Execution time: {$executionTime}s\n";