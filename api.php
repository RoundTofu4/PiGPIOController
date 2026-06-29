<?php
/**
 * Raspberry Pi GPIO 17 Relay Controller API
 * Supports real pinctrl execution on Raspberry Pi and automatic Mock Mode on laptop.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Configuration
define('PINCTRL_CMD', 'pinctrl'); // Modify to 'sudo pinctrl' if permissions require it
define('MOCK_FILE', __DIR__ . '/mock_state.json');

// Check if pinctrl is available
$is_real_pi = false;
$output = [];
$return_var = -1;
@exec('which ' . escapeshellarg(PINCTRL_CMD), $output, $return_var);
if ($return_var === 0) {
    $is_real_pi = true;
}

// Helper to get mock state
function get_mock_state() {
    if (!file_exists(MOCK_FILE)) {
        $initial_state = [
            '17' => ['func' => 'op', 'level' => 'hi', 'raw' => '17: op dh pd | hi (Mocked)'], // Default relay OFF (low trigger, so hi = off)
            '18' => ['func' => 'ip', 'level' => 'lo', 'raw' => '18: ip pd | lo (Mocked)']
        ];
        file_put_contents(MOCK_FILE, json_encode($initial_state, JSON_PRETTY_PRINT));
        return $initial_state;
    }
    return json_decode(file_get_contents(MOCK_FILE), true);
}

// Helper to save mock state
function save_mock_state($state) {
    file_put_contents(MOCK_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

// Parser for pinctrl output
function parse_pinctrl_line($line) {
    // Expected format: "17: op dh pd | hi" or "18: ip pd | lo // comment"
    $line = trim($line);
    if (empty($line)) {
        return null;
    }

    $parts = explode(':', $line, 2);
    if (count($parts) < 2) {
        return null;
    }

    $pin = trim($parts[0]);
    $right = trim($parts[1]);

    $subparts = explode('|', $right, 2);
    $config = trim($subparts[0]);
    $level_str = isset($subparts[1]) ? trim($subparts[1]) : '';

    // Extract function (op, ip, alt0, etc.)
    $config_words = preg_split('/\s+/', $config);
    $func = isset($config_words[0]) ? $config_words[0] : 'unknown';

    // Strip comments starting with '//' from the level string
    $level_part = trim(explode('//', $level_str)[0]);
    
    // Extract first word of the level part (ignoring trailing whitespace/text)
    $level_words = preg_split('/\s+/', $level_part);
    $level_word = isset($level_words[0]) ? strtolower(trim($level_words[0])) : '';

    // Determine level (hi / lo)
    $level = 'unknown';
    if ($level_word === 'hi' || $level_word === '1' || strpos($level_word, 'high') !== false) {
        $level = 'hi';
    } elseif ($level_word === 'lo' || $level_word === '0' || strpos($level_word, 'low') !== false) {
        $level = 'lo';
    }

    return [
        'pin' => $pin,
        'func' => $func,
        'level' => $level,
        'raw' => $line
    ];
}

// Get status of pins 17 and 18
function get_pins_status($is_real_pi) {
    if ($is_real_pi) {
        $status = [];
        foreach ([17, 18] as $pin) {
            $output = [];
            $cmd = PINCTRL_CMD . ' get ' . $pin;
            @exec($cmd . ' 2>&1', $output);
            
            $line = isset($output[0]) ? $output[0] : '';
            $parsed = parse_pinctrl_line($line);
            
            if ($parsed) {
                $status[$pin] = $parsed;
            } else {
                $status[$pin] = [
                    'pin' => (string)$pin,
                    'func' => 'unknown',
                    'level' => 'unknown',
                    'raw' => !empty($line) ? $line : "Error running command: $cmd"
                ];
            }
        }
        return $status;
    } else {
        return get_mock_state();
    }
}

// Execute command to set pin state
function set_pin_state($pin, $level, $is_real_pi) {
    // For GPIO 17: op dh (high = relay OFF), op dl (low = relay ON)
    $dir_val = ($level === 'hi') ? 'dh' : 'dl';
    
    if ($is_real_pi) {
        $cmd = PINCTRL_CMD . " set " . (int)$pin . " op " . $dir_val;
        $output = [];
        $return_var = -1;
        @exec($cmd . ' 2>&1', $output, $return_var);
        return $return_var === 0;
    } else {
        $state = get_mock_state();
        if (isset($state[$pin])) {
            $state[$pin]['func'] = 'op';
            $state[$pin]['level'] = $level;
            $state[$pin]['raw'] = "{$pin}: op {$dir_val} pd | {$level} (Mocked)";
            save_mock_state($state);
            return true;
        }
        return false;
    }
}

// Router actions
$action = isset($_GET['action']) ? $_GET['action'] : 'status';

if ($action === 'status') {
    $pins = get_pins_status($is_real_pi);
    
    // Determine relay status based on low level trigger
    // LOW (lo) = Relay ON, HIGH (hi) = Relay OFF
    $relay_level = isset($pins['17']['level']) ? $pins['17']['level'] : 'unknown';
    $relay_state = 'UNKNOWN';
    if ($relay_level === 'lo') {
        $relay_state = 'ON';
    } elseif ($relay_level === 'hi') {
        $relay_state = 'OFF';
    }
    
    echo json_encode([
        'success' => true,
        'mode' => $is_real_pi ? 'Raspberry Pi (Real)' : 'Laptop (Mock Mode)',
        'relay_status' => $relay_state,
        'pins' => $pins
    ]);
    exit;
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get current state
    $pins = get_pins_status($is_real_pi);
    $current_level = isset($pins['17']['level']) ? $pins['17']['level'] : 'hi';
    
    // Toggle state: if hi (OFF), turn lo (ON). If lo (ON), turn hi (OFF).
    $next_level = ($current_level === 'lo') ? 'hi' : 'lo';
    
    $success = set_pin_state(17, $next_level, $is_real_pi);
    
    // If mock mode, let's also randomly simulate a toggle on GPIO 18 sometimes to show it working
    if (!$is_real_pi) {
        $state = get_mock_state();
        if (rand(1, 10) > 7) { // 30% chance to toggle input pin 18 for demo purposes
            $next_18_level = ($state['18']['level'] === 'lo') ? 'hi' : 'lo';
            $state['18']['level'] = $next_18_level;
            $state['18']['raw'] = "18: ip pd | {$next_18_level} (Mocked)";
            save_mock_state($state);
        }
    }
    
    // Fetch fresh status
    $pins = get_pins_status($is_real_pi);
    $relay_level = isset($pins['17']['level']) ? $pins['17']['level'] : 'unknown';
    $relay_state = 'UNKNOWN';
    if ($relay_level === 'lo') {
        $relay_state = 'ON';
    } elseif ($relay_level === 'hi') {
        $relay_state = 'OFF';
    }
    
    echo json_encode([
        'success' => $success,
        'mode' => $is_real_pi ? 'Raspberry Pi (Real)' : 'Laptop (Mock Mode)',
        'relay_status' => $relay_state,
        'pins' => $pins
    ]);
    exit;
}

// Specific controls (useful for direct button mapping)
if (($action === 'on' || $action === 'off') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_level = ($action === 'on') ? 'lo' : 'hi'; // Low-level trigger: ON is lo, OFF is hi
    $success = set_pin_state(17, $target_level, $is_real_pi);
    
    $pins = get_pins_status($is_real_pi);
    $relay_level = isset($pins['17']['level']) ? $pins['17']['level'] : 'unknown';
    $relay_state = 'UNKNOWN';
    if ($relay_level === 'lo') {
        $relay_state = 'ON';
    } elseif ($relay_level === 'hi') {
        $relay_state = 'OFF';
    }
    
    echo json_encode([
        'success' => $success,
        'mode' => $is_real_pi ? 'Raspberry Pi (Real)' : 'Laptop (Mock Mode)',
        'relay_status' => $relay_state,
        'pins' => $pins
    ]);
    exit;
}

// Fallback for unsupported actions
echo json_encode([
    'success' => false,
    'error' => 'Invalid action or request method'
]);
