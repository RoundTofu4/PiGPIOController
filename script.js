document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const connectionBadge = document.getElementById('connection-badge');
    const badgeText = connectionBadge.querySelector('.badge-text');
    
    const relayToggleBtn = document.getElementById('relay-toggle-btn');
    const relayStateText = document.getElementById('relay-state-text');
    
    const raw17 = document.getElementById('raw-17');
    const raw18 = document.getElementById('raw-18');
    
    const pin17Row = document.getElementById('pin-17-row');
    const pin18Row = document.getElementById('pin-18-row');
    
    const refreshBtn = document.getElementById('refresh-btn');
    const clearLogBtn = document.getElementById('clear-log-btn');
    const consoleLog = document.getElementById('console-log');

    // API URL relative path
    const API_URL = 'api.php';
    let pollingInterval = null;
    let isRequestPending = false;

    // Helper: Add Console Log line
    function logMessage(text, type = 'info') {
        const time = new Date().toLocaleTimeString();
        const logLine = document.createElement('div');
        logLine.className = `log-line log-${type}`;
        logLine.textContent = `[${time}] ${text}`;
        consoleLog.appendChild(logLine);
        consoleLog.scrollTop = consoleLog.scrollHeight;
        
        // Keep logs under 100 entries to prevent memory leak
        while (consoleLog.childNodes.length > 100) {
            consoleLog.removeChild(consoleLog.firstChild);
        }
    }

    // Update individual pin UI row
    function updatePinRowUI(rowEl, levelEl, rawEl, pinData) {
        if (!pinData) return;
        
        // Update raw output
        rawEl.textContent = pinData.raw || `No raw info`;
        
        // Update function badge
        const badgeEl = rowEl.querySelector('.pin-badge');
        if (badgeEl) {
            badgeEl.textContent = (pinData.func || 'unknown').toUpperCase();
            if (pinData.func === 'op') {
                badgeEl.className = 'pin-badge mode-out';
            } else if (pinData.func === 'ip') {
                badgeEl.className = 'pin-badge mode-in';
            }
        }
        
        // Update level indicator
        const lvl = pinData.level || 'unknown';
        const levelTextEl = rowEl.querySelector('.pin-level');
        if (levelTextEl) {
            if (lvl === 'hi') {
                levelTextEl.textContent = 'HIGH (3.3V)';
                levelTextEl.className = 'pin-level level-high';
            } else if (lvl === 'lo') {
                levelTextEl.textContent = 'LOW (0V)';
                levelTextEl.className = 'pin-level level-low';
            } else {
                levelTextEl.textContent = 'UNKNOWN';
                levelTextEl.className = 'pin-level level-unknown';
            }
        }
    }

    // Process status response data and update all UI elements
    function updateUI(data) {
        if (!data || !data.success) {
            logMessage('API reported error: ' + (data.error || 'Unknown error'), 'error');
            return;
        }

        // 1. Connection / Mode Badge Update
        if (data.mode) {
            if (data.mode.includes('Mock')) {
                connectionBadge.className = 'badge badge-mock';
                badgeText.textContent = 'Mock Mode (Laptop)';
            } else {
                connectionBadge.className = 'badge badge-pi';
                badgeText.textContent = 'Raspberry Pi 3B';
            }
        }

        // 2. Relay Button State Update
        const relayStatus = data.relay_status; // "ON" or "OFF"
        relayStateText.textContent = relayStatus;
        
        if (relayStatus === 'ON') {
            relayStateText.className = 'status-value status-val-on';
            relayToggleBtn.className = 'power-switch switch-on';
        } else if (relayStatus === 'OFF') {
            relayStateText.className = 'status-value status-val-off';
            relayToggleBtn.className = 'power-switch switch-off';
        } else {
            relayStateText.className = 'status-value status-val-off';
            relayToggleBtn.className = 'power-switch';
        }

        // 3. Pin Monitor Update
        if (data.pins) {
            updatePinRowUI(pin17Row, null, raw17, data.pins['17']);
            updatePinRowUI(pin18Row, null, raw18, data.pins['18']);
        }
    }

    // Fetch Status from PHP backend
    async function fetchStatus(isSilent = false) {
        if (isRequestPending) return;
        
        isRequestPending = true;
        try {
            const response = await fetch(`${API_URL}?action=status`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const data = await response.json();
            updateUI(data);
            if (!isSilent) {
                logMessage(`Status updated successfully. Mode: ${data.mode}`, 'success');
            }
        } catch (error) {
            console.error('Fetch error:', error);
            connectionBadge.className = 'badge badge-loading';
            badgeText.textContent = 'Disconnected';
            logMessage(`API Connection Error: ${error.message}`, 'error');
        } finally {
            isRequestPending = false;
        }
    }

    // Toggle Relay state
    async function toggleRelay() {
        if (isRequestPending) return;
        
        isRequestPending = true;
        logMessage('Sending toggle request...', 'info');
        
        try {
            const response = await fetch(`${API_URL}?action=toggle`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                }
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            if (data.success) {
                updateUI(data);
                logMessage(`Relay toggled: now ${data.relay_status}`, 'success');
            } else {
                logMessage(`Toggle failed: ${data.error || 'Server error'}`, 'error');
            }
        } catch (error) {
            console.error('Toggle error:', error);
            logMessage(`Failed to send toggle command: ${error.message}`, 'error');
        } finally {
            isRequestPending = false;
        }
    }

    // Event Listeners
    relayToggleBtn.addEventListener('click', toggleRelay);
    
    refreshBtn.addEventListener('click', () => {
        logMessage('Manual refresh triggered...', 'info');
        fetchStatus(false);
    });

    clearLogBtn.addEventListener('click', () => {
        consoleLog.innerHTML = '';
        logMessage('Console log cleared.', 'info');
    });

    // Initialize App
    fetchStatus(false);
    
    // Setup interval polling (every 2 seconds)
    pollingInterval = setInterval(() => {
        fetchStatus(true);
    }, 2000);
});
