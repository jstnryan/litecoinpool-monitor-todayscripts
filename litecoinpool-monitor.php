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

// Truncate username from worker name
//  example: account.worker -> worker
$shortNames = true;

// Rate denominations, modify to preference; example: 'kH/s'->'k', 'GH/s'->'G'
$rateMagnitude = [
    0 => 'H/s',     //10^0
    1 => 'kH/s',    //10^3  "kilo-"
    2 => 'MH/s',    //10^6  "mega-"
    3 => 'GH/s',    //10^9  "giga-"
    4 => 'TH/s',    //10^12 "tera-"
    5 => 'PH/s',    //10^15 "peta-"
    6 => 'EH/s',    //10^18 "exa-"
    7 => 'ZH/s',    //10^21 "zeta-"
    8 => 'YH/s',    //10^24 "yotta-"
];
// Rate method, favor larger accuracy or shorter (concise) number;
//  * 'floor' - force the longest, but most accurage number in a lower magnitude
//  * 'round' - happy medium of options
//  * 'ceil'  - force shortest, less accurate number in higher magnitude
//
//  example: "round" = 34,945.0kH/s, "ceil" = 34.9MH/s
//           "round" = 989.0kH/s, "floor" = 989,000.0H/s
$rateMethod = 'ceil'; //[floor, round, ceil]

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
        if ($worker['hash_rate_24h'] === 0) {
            if (!$worker['connected']) {
                //this worker hasn't started yet, or has been abandoned long
                // enough for average to drop to zero
                $w['fault'] = 1;
            }
        } else {
            if (!$worker['connected']) {
                //this worker has become disconnected
                $w['fault'] = setFault(2);
            }
        }

        $w['name'] = $shortNames ? substr($name, strrpos($name, '.') + 1) : $name;
        if (strlen($w['name']) > $maxCol[0]) {
            $maxCol[0] = strlen($w['name']);
        }
        $w['rate'] = rateFormat($worker['hash_rate']);
        if (strlen($w['rate']) > $maxCol[1]) {
            $maxCol[1] = strlen($w['rate']);
        }
        $w['ave'] = rateFormat($worker['hash_rate_24h']);
        if (strlen($w['ave']) > $maxCol[2]) {
            $maxCol[2] = strlen($w['ave']);
        }
        $workers[] = $w;
    }
}
$workerLines = str_pad('Worker', $maxCol[0]) . '  ' . str_pad('Speed', $maxCol[1]) . '  ' . str_pad('Av.Spd', $maxCol[2]) . "\n";
foreach ($workers as $w) {
    $workerLines .= lineColor(str_pad($w['name'], $maxCol[0]) . '  ' . str_pad($w['rate'], $maxCol[1], ' ', STR_PAD_LEFT) . '  ' . str_pad($w['ave'], $maxCol[2], ' ', STR_PAD_LEFT) . "\n", $w['fault']);
}

echo 'Current Speed: ' . rateFormat($obj['user']['hash_rate']) ."\n";
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

function rateFormat($rate) {
    global $rateMagnitude, $rateMethod;

    $magnitude = ($rate == 0) ? 0 : call_user_func($rateMethod, floor(log10($rate)) / 3);
    $adjusted = ($rate * 1000) / (1000 ** $magnitude);
    return number_format($adjusted, 1, '.', ',') . $rateMagnitude[$magnitude];
}
