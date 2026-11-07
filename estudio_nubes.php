<?php
// =========================================================================
// ☁️ ESTUDIO DE BASE DE NUBE: previsión «Base de nube» (ICON-EU, humedad por niveles) frente al METAR
// =========================================================================
// Se ejecuta al final de estudio.php (reutiliza $dataMeteo, $nowTs y $fechaCaptura) y escribe estudio_nubes.csv.
// Cada aeropuerto METAR se empareja con el despegue más cercano a ≤ NUBES_MAX_KM y con una diferencia de altitud
// ≤ NUBES_MAX_DESNIVEL (la previsión se calcula desde la altitud del despegue hacia arriba; el METAR mide desde el
// aeropuerto). Por cada pareja y hora se guarda el METAR actual (capas en bruto) y la base de nube prevista a
// +6/+24/+48/+72/+96 h. El cruce previsión-realidad lo hace el panel (index.html), como con el viento.
// No hace ninguna llamada nueva a APIs: lee balizas_metar_6h.json y meteo-datos.json, que ya existen en el servidor.

const NUBES_MAX_KM = 20;          // distancia máxima aeropuerto-despegue
const NUBES_MAX_DESNIVEL = 500;   // m de diferencia máxima de altitud entre despegue y aeropuerto
const NUBES_HORIZONTES = [6, 24, 48, 72, 96]; // ICON-EU llega a 5 días (base_nube tiene 120 horas)

function nubes_ejecutar($dataMeteo, $nowTs, $fechaCaptura) {
    $raiz = dirname(__DIR__);
    $fileCsv = __DIR__ . '/estudio_nubes.csv';

    $aeropuertos = json_decode(@file_get_contents($raiz . '/balizas_metar_arraybalizas.json'), true);
    $metar6h     = json_decode(@file_get_contents($raiz . '/balizas_metar_6h.json'), true);
    if (!is_array($aeropuertos) || !is_array($metar6h) || empty($dataMeteo['despegues'])) {
        echo "☁️ estudio_nubes: faltan datos (aeropuertos, METAR o meteo-datos.json)\n";
        return;
    }

    // 1. Parejas aeropuerto → despegue más cercano que cumpla distancia y desnivel
    $parejas = [];
    foreach ($aeropuertos as $a) {
        if (!isset($a['id'], $a['latitude'], $a['longitude'], $a['altitude'])) continue;
        $mejor = null;
        foreach ($dataMeteo['despegues'] as $idx => $d) {
            $alt = (float)str_replace(',', '.', $d['Altitud'] ?? 0);
            if ($alt <= 0 || abs($alt - (float)$a['altitude']) > NUBES_MAX_DESNIVEL) continue;
            if (empty($dataMeteo['respuestas'][$idx]['hourly']['base_nube'])) continue;
            $km = nubes_distanciaKm((float)$a['latitude'], (float)$a['longitude'],
                (float)str_replace(',', '.', $d['Latitud'] ?? 0), (float)str_replace(',', '.', $d['Longitud'] ?? 0));
            if ($km <= NUBES_MAX_KM && ($mejor === null || $km < $mejor['km'])) $mejor = ['idx' => $idx, 'km' => $km, 'alt' => $alt];
        }
        if ($mejor !== null) $parejas[] = ['aeropuerto' => $a] + $mejor;
    }

    // 2. Cabecera y anti-duplicados (una fila por aeropuerto y hora)
    $cabecera = ['momento_captura', 'icao', 'aeropuerto', 'elev_aeropuerto', 'id_despegue', 'despegue', 'altitud_despegue', 'distancia_km',
                 'real_momento', 'real_nubes'];
    foreach (NUBES_HORIZONTES as $h) { $cabecera[] = "{$h}h_pronostico_momento"; $cabecera[] = "{$h}h_base_nube"; }

    $existe = file_exists($fileCsv) && filesize($fileCsv) > 0;
    $yaCapturadas = [];
    if ($existe && ($hr = @fopen($fileCsv, 'r'))) {
        $tam = filesize($fileCsv);
        if ($tam > 100000) { fseek($hr, -100000, SEEK_END); fgets($hr); }
        while (($f = fgetcsv($hr, 2000, ';')) !== false) {
            if (isset($f[0], $f[1]) && $f[0] !== 'momento_captura') $yaCapturadas[$f[1] . '_' . substr($f[0], 0, 13)] = true;
        }
        fclose($hr);
    }

    $fh = @fopen($fileCsv, 'a');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) { if ($fh) fclose($fh); echo "☁️ estudio_nubes: CSV ocupado o no escribible\n"; return; }
    if (!$existe) fputcsv($fh, $cabecera, ';');

    // 3. Filas
    $horaClave = substr($fechaCaptura, 0, 13);
    $escritas = 0;
    foreach ($parejas as $p) {
        $a = $p['aeropuerto'];
        if (isset($yaCapturadas[$a['id'] . '_' . $horaClave])) continue;

        // METAR más cercano a la hora de captura (±45 min) y que traiga el campo de nubes
        $real = null;
        foreach ($metar6h[$a['id']] ?? [] as $lectura) {
            if (!isset($lectura['ts'], $lectura['nubes'])) continue;
            $dif = abs($lectura['ts'] - $nowTs);
            if ($dif <= 2700 && ($real === null || $dif < abs($real['ts'] - $nowTs))) $real = $lectura;
        }

        $despegue = $dataMeteo['despegues'][$p['idx']];
        $hourly   = $dataMeteo['respuestas'][$p['idx']]['hourly'];
        $fila = [$fechaCaptura, $a['id'], $a['name'] ?? '', round($a['altitude']), (function_exists('extraerIdDespegue') ? extraerIdDespegue($despegue) : ($despegue['ID'] ?? '')), $despegue['Despegue'] ?? '',
                 round($p['alt']), round($p['km'], 1), $real ? date('Y-m-d H:i', $real['ts']) : '', $real['nubes'] ?? ''];

        // base_nube va por índice de hora desde time[0] (00:00 UTC de hoy) y llega a 120 horas aunque time tenga 96
        $t0 = isset($hourly['time'][0]) ? strtotime($hourly['time'][0] . ' UTC') : false;
        foreach (NUBES_HORIZONTES as $h) {
            $momento = ''; $valor = '';
            if ($t0 !== false) {
                $i = (int)round(($nowTs + $h * 3600 - $t0) / 3600);
                if ($i >= 0 && $i < count($hourly['base_nube'])) {
                    $momento = date('Y-m-d H:i', $t0 + $i * 3600);
                    $v = $hourly['base_nube'][$i];
                    $valor = ($v === null) ? '' : round($v); // m MSL; ≤ altitud = despegue en la nube; -1 = sin nubes bajas
                }
            }
            $fila[] = $momento; $fila[] = $valor;
        }

        if (fputcsv($fh, $fila, ';') !== false) $escritas++;
    }

    flock($fh, LOCK_UN);
    fclose($fh);
    echo "☁️ estudio_nubes: " . count($parejas) . " aeropuertos emparejados | filas escritas: $escritas\n";
}

function nubes_distanciaKm($lat1, $lon1, $lat2, $lon2) {
    $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
    $x = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 12742 * asin(min(1, sqrt($x)));
}

if (isset($dataMeteo, $nowTs, $fechaCaptura)) {
    nubes_ejecutar($dataMeteo, $nowTs, $fechaCaptura);
}
