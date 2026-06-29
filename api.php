<?php
/**
 * Raspberry Pi GPIO Relay Controller API - Dynamic Version with Momentary Mode
 * Supports dynamic configuration via config.json, real pinctrl execution, and laptop mocking.
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
define('CONFIG_FILE', __DIR__ . '/config.json');
define('MOCK_FILE', __DIR__ . '/mock_state.json');

// Check if pinctrl is available
$is_real_pi = false;
$output = [];
$return_var = -1;
@exec('which ' . escapeshellarg(PINCTRL_CMD), $output, $return_var);
if ($return_var === 0) {
    $is_real_pi = true;
}

// Helper to get configuration
function get_pin_config() {
    if (!file_exists(CONFIG_FILE)) {
        $default_config = [
            'pins' => [
                [
                    'pin' => '17',
                    'name' => 'Lampu 1',
                    'mode' => 'control',
                    'active_low' => true
                ],
                [
                    'pin' => '18',
                    'name' => 'Feedback Sensor',
                    'mode' => 'monitor',
                    'active_low' => false
                ]
            ]
        ];
        file_put_contents(CONFIG_FILE, json_encode($default_config, JSON_PRETTY_PRINT));
        return $default_config;
    }
    $config = json_decode(file_get_contents(CONFIG_FILE), true);
    if (!isset($config['pins']) || !is_array($config['pins'])) {
        return ['pins' => []];
    }
    return $config;
}

// Helper to save configuration
function save_pin_config($config) {
    return file_put_contents(CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT)) !== false;
}

// Helper to get mock state
function get_mock_state($pins_config) {
    $mock_state = [];
    if (file_exists(MOCK_FILE)) {
        $mock_state = json_decode(file_get_contents(MOCK_FILE), true);
    }
    
    // Synchronize mock_state with current pins_config
    $updated = false;
    $active_pins = [];
    
    foreach ($pins_config['pins'] as $p) {
        $pin_num = $p['pin'];
        $active_pins[] = $pin_num;
        
        if (!isset($mock_state[$pin_num])) {
            // Initialize new mock pin
            $is_output_mode = ($p['mode'] === 'control' || $p['mode'] === 'momentary');
            $def_level = $is_output_mode ? ($p['active_low'] ? 'hi' : 'lo') : 'lo'; // hi means relay off initially if active_low
            $def_func = $is_output_mode ? 'op' : 'ip';
            $dir_val = ($def_level === 'hi') ? 'dh' : 'dl';
            $mock_state[$pin_num] = [
                'pin' => $pin_num,
                'func' => $def_func,
                'level' => $def_level,
                'raw' => "{$pin_num}: {$def_func} {$dir_val} pd | {$def_level} (Mocked)"
            ];
            $updated = true;
        }
    }
    
    // Remove pins from mock state that are no longer in configuration
    foreach ($mock_state as $pin_num => $data) {
        if (!in_array($pin_num, $active_pins)) {
            unset($mock_state[$pin_num]);
            $updated = true;
        }
    }
    
    if ($updated) {
        file_put_contents(MOCK_FILE, json_encode($mock_state, JSON_PRETTY_PRINT));
    }
    
    return $mock_state;
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

// Get status of configured pins
function get_pins_status($config, $is_real_pi) {
    if ($is_real_pi) {
        $status = [];
        foreach ($config['pins'] as $p) {
            $pin = $p['pin'];
            $output = [];
            $cmd = PINCTRL_CMD . ' get ' . (int)$pin;
            @exec($cmd . ' 2>&1', $output);
            
            $line = isset($output[0]) ? $output[0] : '';
            $parsed = parse_pinctrl_line($line);
            
            if ($parsed) {
                // If it parsed, merge it with configuration details
                $status[$pin] = array_merge($p, $parsed);
            } else {
                $status[$pin] = array_merge($p, [
                    'func' => 'unknown',
                    'level' => 'unknown',
                    'raw' => !empty($line) ? $line : "Error running command: $cmd"
                ]);
            }
            
            // Calculate active state for controls
            if ($p['mode'] === 'control' || $p['mode'] === 'momentary') {
                $lvl = $status[$pin]['level'];
                if ($lvl === 'unknown') {
                    $status[$pin]['status'] = 'UNKNOWN';
                } else {
                    $isOn = $p['active_low'] ? ($lvl === 'lo') : ($lvl === 'hi');
                    $status[$pin]['status'] = $isOn ? 'ON' : 'OFF';
                }
            }
        }
        return $status;
    } else {
        $mock_state = get_mock_state($config);
        $status = [];
        foreach ($config['pins'] as $p) {
            $pin = $p['pin'];
            $is_output_mode = ($p['mode'] === 'control' || $p['mode'] === 'momentary');
            $mock_pin_data = isset($mock_state[$pin]) ? $mock_state[$pin] : [
                'pin' => $pin,
                'func' => $is_output_mode ? 'op' : 'ip',
                'level' => ($is_output_mode && $p['active_low']) ? 'hi' : 'lo',
                'raw' => "{$pin}: op | (Mocked Default)"
            ];
            
            $status[$pin] = array_merge($p, $mock_pin_data);
            
            // Calculate active state for controls
            if ($p['mode'] === 'control' || $p['mode'] === 'momentary') {
                $lvl = $status[$pin]['level'];
                $isOn = $p['active_low'] ? ($lvl === 'lo') : ($lvl === 'hi');
                $status[$pin]['status'] = $isOn ? 'ON' : 'OFF';
            }
        }
        return $status;
    }
}

// Execute command to set pin state
function set_pin_state($pin, $level, $is_real_pi, $config) {
    // For active output setup: op dh or op dl
    $dir_val = ($level === 'hi') ? 'dh' : 'dl';
    
    if ($is_real_pi) {
        $cmd = PINCTRL_CMD . " set " . (int)$pin . " op " . $dir_val;
        $output = [];
        $return_var = -1;
        @exec($cmd . ' 2>&1', $output, $return_var);
        return $return_var === 0;
    } else {
        $state = get_mock_state($config);
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
$config = get_pin_config();

if ($action === 'status') {
    $pins = get_pins_status($config, $is_real_pi);
    
    echo json_encode([
        'success' => true,
        'mode' => $is_real_pi ? 'Raspberry Pi (Real)' : 'Laptop (Mock Mode)',
        'pins' => $pins
    ]);
    exit;
}

if ($action === 'toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin = isset($_POST['pin']) ? $_POST['pin'] : '';
    
    if (empty($pin)) {
        // Fallback to reading JSON input if content-type is json
        $raw_input = file_get_contents('php://input');
        $json_data = json_decode($raw_input, true);
        if (isset($json_data['pin'])) {
            $pin = $json_data['pin'];
        }
    }
    
    if (empty($pin)) {
        echo json_encode(['success' => false, 'error' => 'Pin parameter missing']);
        exit;
    }
    
    // Find configuration for this pin
    $pin_conf = null;
    foreach ($config['pins'] as $p) {
        if ($p['pin'] === (string)$pin) {
            $pin_conf = $p;
            break;
        }
    }
    
    if (!$pin_conf || ($pin_conf['mode'] !== 'control' && $pin_conf['mode'] !== 'momentary')) {
        echo json_encode(['success' => false, 'error' => 'Pin is not configured for control output']);
        exit;
    }
    
    $success = false;
    
    if ($pin_conf['mode'] === 'momentary') {
        // Safe backend Momentary pulse execution
        $active_level = $pin_conf['active_low'] ? 'lo' : 'hi';
        $inactive_level = $pin_conf['active_low'] ? 'hi' : 'lo';
        $duration = isset($pin_conf['pulse_duration']) ? (int)$pin_conf['pulse_duration'] : 1000;
        
        // 1. Trigger Pulse ON
        $success = set_pin_state($pin, $active_level, $is_real_pi, $config);
        
        if ($success) {
            // 2. Wait
            usleep($duration * 1000);
            // 3. Trigger Pulse OFF (Restore to idle)
            set_pin_state($pin, $inactive_level, $is_real_pi, $config);
        }
    } else {
        // Latch mode toggling (Standard control)
        $pins = get_pins_status($config, $is_real_pi);
        $current_level = isset($pins[$pin]['level']) ? $pins[$pin]['level'] : 'hi';
        $next_level = ($current_level === 'lo') ? 'hi' : 'lo';
        
        $success = set_pin_state($pin, $next_level, $is_real_pi, $config);
    }
    
    // For mock mode: also randomly toggle any monitor pins to simulate sensory feedback changes
    if (!$is_real_pi) {
        $state = get_mock_state($config);
        foreach ($config['pins'] as $p) {
            if ($p['mode'] === 'monitor' && rand(1, 10) > 7) {
                $pin_m = $p['pin'];
                if (isset($state[$pin_m])) {
                    $next_m_level = ($state[$pin_m]['level'] === 'lo') ? 'hi' : 'lo';
                    $state[$pin_m]['level'] = $next_m_level;
                    $state[$pin_m]['raw'] = "{$pin_m}: ip pd | {$next_m_level} (Mocked)";
                }
            }
        }
        save_mock_state($state);
    }
    
    // Fetch updated statuses
    $pins = get_pins_status($config, $is_real_pi);
    
    echo json_encode([
        'success' => $success,
        'mode' => $is_real_pi ? 'Raspberry Pi (Real)' : 'Laptop (Mock Mode)',
        'pins' => $pins
    ]);
    exit;
}

if ($action === 'save_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_input = file_get_contents('php://input');
    $json_data = json_decode($raw_input, true);
    
    if (!isset($json_data['pins']) || !is_array($json_data['pins'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid configuration data format']);
        exit;
    }
    
    $validated_pins = [];
    $existing_pins = [];
    
    foreach ($json_data['pins'] as $p) {
        if (!isset($p['pin']) || !isset($p['name']) || !isset($p['mode'])) {
            echo json_encode(['success' => false, 'error' => 'Each pin must have a number, name, and mode']);
            exit;
        }
        
        $pin_num = trim($p['pin']);
        if (!is_numeric($pin_num) || (int)$pin_num < 1 || (int)$pin_num > 40) {
            echo json_encode(['success' => false, 'error' => "Invalid GPIO pin number: {$pin_num}. Must be between 1 and 40."]);
            exit;
        }
        
        if (in_array($pin_num, $existing_pins)) {
            echo json_encode(['success' => false, 'error' => "Duplicate configuration for GPIO Pin {$pin_num}."]);
            exit;
        }
        $existing_pins[] = $pin_num;
        
        $name = trim($p['name']);
        if (empty($name)) {
            $name = "GPIO " . $pin_num;
        }
        
        // Mode validation: control (latch), momentary (pulse), monitor (input)
        $mode = 'monitor';
        if ($p['mode'] === 'control') {
            $mode = 'control';
        } elseif ($p['mode'] === 'momentary') {
            $mode = 'momentary';
        }
        
        $active_low = isset($p['active_low']) ? (bool)$p['active_low'] : false;
        
        $pulse_duration = 1000;
        if ($mode === 'momentary') {
            $pulse_duration = isset($p['pulse_duration']) ? (int)$p['pulse_duration'] : 1000;
            if ($pulse_duration < 100 || $pulse_duration > 5000) {
                echo json_encode(['success' => false, 'error' => 'Pulse duration must be between 100ms and 5000ms']);
                exit;
            }
        }
        
        $validated_pins[] = [
            'pin' => (string)$pin_num,
            'name' => $name,
            'mode' => $mode,
            'active_low' => $active_low,
            'pulse_duration' => $pulse_duration
        ];
    }
    
    $new_config = ['pins' => $validated_pins];
    $success = save_pin_config($new_config);
    
    if ($success) {
        // Force synchronization of mock file if we are in mock mode
        if (!$is_real_pi) {
            get_mock_state($new_config);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuration saved successfully',
            'pins' => get_pins_status($new_config, $is_real_pi)
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to write configuration file']);
    }
    exit;
}

// Fallback for unsupported actions
echo json_encode([
    'success' => false,
    'error' => 'Invalid action or request method'
]);
