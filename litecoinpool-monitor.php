<?php

// SETTINGS: Enter your LitecoinPool API Key between the quotes below
// You can find your API key on your LitecoinPool.org account page:
//  https://www.litecoinpool.org/account
$apiKey = 'ENTER_YOUR_API_KEY_HERE';

// Low hash rate threshold (percent); if a worker falls below these thresholds
// (current vs 24 average) a corresponding warning level will be triggered
$lowRate = [
    'low' => 10,
    'high' => 20,
];

////////////////////////////////////////////////////////////////////////////////
//                       DO NOT EDIT BELOW THIS LINE                          //
////////////////////////////////////////////////////////////////////////////////

// Copyright 2019 Justin Ryan <jstn@jstnryan.com>

if ($apiKey === 'ENTER_YOUR_API_KEY_HERE') {
    echo lineColor("LitecoinPool API Key not provided.\n", 'yellow');
    echo "Please edit the script settings.\n";
    die;
}

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_URL => 'https://www.litecoinpool.org/api?api_key=' . $apiKey,
]);
$response = curl_exec($curl);
curl_close($curl);

if ($response === false || empty($response)) {
    echo lineColor("Failed to get a response from server.\n", 'yellow');
    die;
} else {
    $obj = json_decode($response, JSON_OBJECT_AS_ARRAY);
}
if ($obj === null) {
    echo lineColor("Unable to parse response from server.\n", 'yellow');
    die;
}

/**
 * Error urgency level
 *
 * 0 = no fault (green)
 * 1 = warning (yellow)
 * 2 = critical (red)
 */
$fault = 0;
$maxCol = [0,0,0];
if (isset($obj['workers']) && !empty($obj['workers'])) {
    foreach ($obj['workers'] as $name => $worker) {
        $w['fault'] = setFault(threshold($worker['hash_rate'], $worker['hash_rate_24h']));
        if (!$worker['connected']) {
            $w['fault'] = setFault(2);
        }

        $w['name'] = $name;
        if (strlen($name) > $maxCol[0]) {
            $maxCol[0] = strlen($name);
        }
        $w['rate'] = number_format($worker['hash_rate'], 1, '.', ',') . 'kH/s';
        if (strlen($w['rate']) > $maxCol[1]) {
            $maxCol[1] = strlen($w['rate']);
        }
        if ($worker['hash_rate'] === 0) {
            if ($worker['hash_rate_24h'] === 0) {
                $w['fault'] = setFault(1);
            } else {
                $w['fault'] = setFault(2);
            }
        }
        $w['ave'] = number_format($worker['hash_rate_24h'], 1, '.', ',') . 'kH/s';
        if (strlen($w['ave']) > $maxCol[2]) {
            $maxCol[2] = strlen($w['ave']);
        }
        $workers[] = $w;
    }
}
$workerLines = str_pad('Worker', $maxCol[0]) . '  ' . str_pad('Speed', $maxCol[1]) . '  ' . str_pad('Av.Spd', $maxCol[2]) . "\n";
foreach ($workers as $w) {
    $workerLines .= lineColor(str_pad($w['name'], $maxCol[0]) . '  ' . str_pad($w['rate'], $maxCol[1]) . '  ' . str_pad($w['ave'], $maxCol[2]) . "\n", $w['fault']);
}

echo 'Current Speed: ' . number_format($obj['user']['hash_rate'], 1, '.', ',') . 'kH/s' ."\n";
echo "\n";
echo $workerLines;

/**
 * Return a string of text with an escape color code
 */
function lineColor($string, $color = null) {
    if (is_null($color)) {
        return $string;
    }
    $result = '';
    if ($color === 0) {
        $result .= "\033[0m";
    } elseif ($color === 1 || $color === 'yellow') {
        $result .= "\033[0;33m";
    } elseif ($color === 2 || $color === 'red') {
        $result .= "\033[0;31m";
    } elseif ($color === 'green') {
        $result .= "\033[0;32m";
    } elseif ($color === 'white') {
        $result .= "\033[0;37m";
    } else {
        $result .= "\033[0m";
    }
    $result .= $string . "\033[0m";
    return $result;
}
/**
 * Elevate global fault level, to highest
 */
function setFault($level) {
    global $fault;

    if ($level > $fault) {
        $fault = $level;
    }
    return $level;
}

/**
 * Return current speed PERCENT BELOW (less than) 24h efficiency average
 */
function speedFactor($dividend, $divisor, $precision = 0) {
    if ($divisor == 0) {
        //not ===, could be string
        return 0; //special case for no previous data
    }
    if ($dividend > $divisor) {
        return 0; //more efficient
    }
    return round(100 - (($dividend / $divisor) * 100), $precision);
}

/**
 * Return the worker error level based on speedFactor
 */
function threshold($speed, $average) {
    global $lowRate;

    $factor = speedFactor($speed, $average, 1);
    if ($factor < $lowRate['low']) {
        return 0;
    } elseif ($factor < $lowRate['high']) {
        return 1;
    }
    return 2;
}
