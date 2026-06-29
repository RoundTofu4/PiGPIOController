document.addEventListener('DOMContentLoaded', () => {
    // DOM Elements
    const connectionBadge = document.getElementById('connection-badge');
    const badgeText = connectionBadge.querySelector('.badge-text');
    
    const controlsSection = document.getElementById('controls-section');
    const monitorListContainer = document.getElementById('monitor-list-container');
    const consoleLog = document.getElementById('console-log');
    const refreshBtn = document.getElementById('refresh-btn');
    const clearLogBtn = document.getElementById('clear-log-btn');

    // Settings Modal DOM Elements
    const settingsModal = document.getElementById('settings-modal');
    const openSettingsBtn = document.getElementById('open-settings-btn');
    const closeSettingsBtn = document.getElementById('close-settings-btn');
    const cancelSettingsBtn = document.getElementById('cancel-settings-btn');
    const saveSettingsBtn = document.getElementById('save-settings-btn');
    const pinsConfigList = document.getElementById('pins-config-list');
    
    // Add Pin Form Elements
    const addPinForm = document.getElementById('add-pin-form');
    const newPinNum = document.getElementById('new-pin-num');
    const newPinName = document.getElementById('new-pin-name');
    const newPinMode = document.getElementById('new-pin-mode');
    const newPinActiveLow = document.getElementById('new-pin-active-low');
    const newPinDuration = document.getElementById('new-pin-duration');
    const activeLowGroup = document.getElementById('active-low-group');
    const pulseDurationGroup = document.getElementById('pulse-duration-group');

    // API URL relative path
    const API_URL = 'api.php';
    let pollingInterval = null;
    let isStatusRequestPending = false;
    const pendingPins = new Set();
    
    // Config state
    let activePins = []; // Stores the current rendered pin configurations
    let tempPins = [];   // Stores changes inside settings modal before saving

    // Toggle form display groups based on selected mode
    newPinMode.addEventListener('change', () => {
        const val = newPinMode.value;
        if (val === 'control') {
            activeLowGroup.style.display = 'flex';
            pulseDurationGroup.style.display = 'none';
        } else if (val === 'momentary') {
            activeLowGroup.style.display = 'flex';
            pulseDurationGroup.style.display = 'flex';
        } else {
            activeLowGroup.style.display = 'none';
            pulseDurationGroup.style.display = 'none';
        }
    });

    // Helper: Add Console Log line
    function logMessage(text, type = 'info') {
        const time = new Date().toLocaleTimeString();
        const logLine = document.createElement('div');
        logLine.className = `log-line log-${type}`;
        logLine.textContent = `[${time}] ${text}`;
        consoleLog.appendChild(logLine);
        consoleLog.scrollTop = consoleLog.scrollHeight;
        
        while (consoleLog.childNodes.length > 100) {
            consoleLog.removeChild(consoleLog.firstChild);
        }
    }

    // Modal Control: Open
    openSettingsBtn.addEventListener('click', () => {
        // Deep copy active pins to temp configuration
        tempPins = JSON.parse(JSON.stringify(activePins));
        renderModalPinsList();
        
        // Reset form inputs
        newPinNum.value = '';
        newPinName.value = '';
        newPinMode.value = 'control';
        newPinActiveLow.checked = true;
        newPinDuration.value = '1000';
        activeLowGroup.style.display = 'flex';
        pulseDurationGroup.style.display = 'none';
        
        settingsModal.classList.add('show');
        logMessage('Configuration panel opened.', 'info');
    });

    // Modal Control: Close
    const closeModal = () => {
        settingsModal.classList.remove('show');
    };
    closeSettingsBtn.addEventListener('click', closeModal);
    cancelSettingsBtn.addEventListener('click', closeModal);

    // Render configuration list in Settings Modal
    function renderModalPinsList() {
        pinsConfigList.innerHTML = '';
        
        if (tempPins.length === 0) {
            pinsConfigList.innerHTML = '<p class="empty-state">No pins configured yet.</p>';
            return;
        }

        tempPins.forEach((p, index) => {
            const row = document.createElement('div');
            row.className = 'config-pin-row';
            
            let pinTypeLabel = '';
            if (p.mode === 'control') {
                pinTypeLabel = `Control Toggle (Active ${p.active_low ? 'LOW' : 'HIGH'})`;
            } else if (p.mode === 'momentary') {
                pinTypeLabel = `Momentary Pulse (${p.pulse_duration || 1000}ms, Active ${p.active_low ? 'LOW' : 'HIGH'})`;
            } else {
                pinTypeLabel = 'Monitor';
            }
                
            row.innerHTML = `
                <div class="config-pin-info">
                    <span class="config-pin-badge">${p.pin}</span>
                    <div>
                        <div class="config-pin-name">${escapeHtml(p.name)}</div>
                        <div style="font-size: 11px; color: var(--text-muted);">${pinTypeLabel}</div>
                    </div>
                </div>
                <div class="config-pin-meta">
                    <button type="button" class="btn-delete" data-index="${index}" title="Remove Pin">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </button>
                </div>
            `;
            
            // Delete button action listener
            row.querySelector('.btn-delete').addEventListener('click', (e) => {
                const idx = parseInt(e.currentTarget.getAttribute('data-index'));
                const removedPin = tempPins[idx];
                tempPins.splice(idx, 1);
                renderModalPinsList();
                logMessage(`Pin GPIO ${removedPin.pin} removed from temp configuration.`, 'warning');
            });
            
            pinsConfigList.appendChild(row);
        });
    }

    // Add New Pin to the temporary list in modal
    addPinForm.addEventListener('submit', (e) => {
        e.preventDefault();
        
        const pinNumStr = newPinNum.value.trim();
        const pinNameStr = newPinName.value.trim();
        const pinModeStr = newPinMode.value;
        const pinActiveLow = newPinActiveLow.checked;
        const pinDurationVal = parseInt(newPinDuration.value) || 1000;

        // Validation
        if (!pinNumStr || isNaN(pinNumStr) || parseInt(pinNumStr) < 1 || parseInt(pinNumStr) > 40) {
            alert('Pin number must be a valid number between 1 and 40.');
            return;
        }

        // Check duplicates
        const exists = tempPins.some(p => p.pin === pinNumStr);
        if (exists) {
            alert(`GPIO Pin ${pinNumStr} is already added to the list.`);
            return;
        }

        const newPinObj = {
            pin: pinNumStr,
            name: pinNameStr || `GPIO ${pinNumStr}`,
            mode: pinModeStr,
            active_low: (pinModeStr === 'control' || pinModeStr === 'momentary') ? pinActiveLow : false
        };

        if (pinModeStr === 'momentary') {
            if (pinDurationVal < 100 || pinDurationVal > 5000) {
                alert('Pulse duration must be between 100ms and 5000ms.');
                return;
            }
            newPinObj.pulse_duration = pinDurationVal;
        }

        // Append to temp array
        tempPins.push(newPinObj);

        // Re-render
        renderModalPinsList();
        logMessage(`Added GPIO ${pinNumStr} (${pinNameStr}) to config list.`, 'info');
        
        // Reset form inputs
        newPinNum.value = '';
        newPinName.value = '';
        newPinDuration.value = '1000';
    });

    // Save configuration settings to the server
    saveSettingsBtn.addEventListener('click', async () => {
        saveSettingsBtn.disabled = true;
        saveSettingsBtn.textContent = 'Saving...';
        
        try {
            const response = await fetch(`${API_URL}?action=save_config`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ pins: tempPins })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            if (data.success) {
                logMessage('Configuration saved successfully to server.', 'success');
                closeModal();
                // Force full re-render of dashboard
                activePins = []; 
                updateDashboardUI(data);
            } else {
                alert(`Failed to save configuration: ${data.error}`);
                logMessage(`Save configuration error: ${data.error}`, 'error');
            }
        } catch (error) {
            console.error('Save config error:', error);
            alert(`Network error saving configuration: ${error.message}`);
            logMessage(`Failed to save configuration: ${error.message}`, 'error');
        } finally {
            saveSettingsBtn.disabled = false;
            saveSettingsBtn.textContent = 'Save Configuration';
        }
    });

    // Re-create DOM elements only when the pin list configuration changes
    function rebuildDashboardLayout(pinsData) {
        // Clear containers
        controlsSection.innerHTML = '';
        monitorListContainer.innerHTML = '';

        const pinKeys = Object.keys(pinsData);
        
        if (pinKeys.length === 0) {
            controlsSection.innerHTML = `
                <div class="card loading-card">
                    <p class="empty-state">No controls configured. Click settings gear to configure GPIO pins.</p>
                </div>
            `;
            monitorListContainer.innerHTML = '<p class="empty-state">No pins to monitor.</p>';
            return;
        }

        let hasControls = false;

        pinKeys.forEach(pinNum => {
            const pinData = pinsData[pinNum];
            
            // 1. Rebuild Control Switches (Left side)
            if (pinData.mode === 'control' || pinData.mode === 'momentary') {
                hasControls = true;
                const card = document.createElement('section');
                card.className = 'card control-card';
                card.id = `control-card-${pinNum}`;
                
                const isMomentary = pinData.mode === 'momentary';
                const buttonClass = isMomentary ? 'power-switch momentary-btn' : 'power-switch';
                const triggerHint = isMomentary 
                    ? `Momentary Pulse (${pinData.pulse_duration || 1000}ms)` 
                    : `Active ${pinData.active_low ? 'LOW' : 'HIGH'} trigger`;
                    
                const initialStatusValue = isMomentary ? 'IDLE' : 'OFF';
                
                card.innerHTML = `
                    <div class="card-header">
                        <h2>${escapeHtml(pinData.name)}</h2>
                        <span class="pin-hint">GPIO ${pinNum}</span>
                    </div>
                    
                    <div class="control-body">
                        <div class="power-switch-container">
                            <button id="toggle-btn-${pinNum}" class="${buttonClass}" aria-label="Toggle Relay">
                                <div class="switch-inner">
                                    <svg class="power-icon" viewBox="0 0 24 24">
                                        <path d="M12 2v10M18.36 5.64A9 9 0 1 1 5.64 5.64" />
                                    </svg>
                                </div>
                            </button>
                        </div>

                        <div class="status-indicator">
                            <span class="status-label">Current State</span>
                            <div id="state-text-${pinNum}" class="status-value status-val-off">${initialStatusValue}</div>
                            <p class="trigger-hint">${triggerHint}</p>
                        </div>
                    </div>
                `;
                
                // Click handler for buttons
                if (isMomentary) {
                    card.querySelector(`.power-switch`).addEventListener('click', () => {
                        pulsePin(pinNum);
                    });
                } else {
                    card.querySelector(`.power-switch`).addEventListener('click', () => {
                        togglePin(pinNum);
                    });
                }
                
                controlsSection.appendChild(card);
            }

            // 2. Rebuild Monitor Pin rows (Right side)
            const pinRow = document.createElement('div');
            pinRow.className = 'pin-row';
            pinRow.id = `pin-row-${pinNum}`;
            
            pinRow.innerHTML = `
                <div class="pin-meta">
                    <span class="pin-num">${pinNum}</span>
                    <span class="pin-name">${escapeHtml(pinData.name)}</span>
                </div>
                <div class="pin-data">
                    <span class="pin-badge" id="mode-badge-${pinNum}">---</span>
                    <span class="pin-level level-unknown" id="level-badge-${pinNum}">---</span>
                </div>
                <div class="raw-console">pinctrl get ${pinNum}: <code id="raw-text-${pinNum}">Loading...</code></div>
            `;
            
            monitorListContainer.appendChild(pinRow);
        });

        if (!hasControls) {
            controlsSection.innerHTML = `
                <div class="card loading-card">
                    <p class="empty-state">No controls configured. Click settings gear to configure GPIO pins.</p>
                </div>
            `;
        }
    }

    // Flicker-free status updates to already rendered DOM elements
    function updateDashboardUI(data) {
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

        const pinsData = data.pins || {};
        const incomingPinNums = Object.keys(pinsData);
        
        // Save raw configurations to local state
        const incomingConfig = incomingPinNums.map(pinNum => {
            const configObj = {
                pin: pinNum,
                name: pinsData[pinNum].name,
                mode: pinsData[pinNum].mode,
                active_low: pinsData[pinNum].active_low
            };
            if (pinsData[pinNum].mode === 'momentary') {
                configObj.pulse_duration = pinsData[pinNum].pulse_duration;
            }
            return configObj;
        });

        // Check if layout needs to be rebuilt
        const configJSONStr = JSON.stringify(incomingConfig);
        const activeJSONStr = JSON.stringify(activePins);
        
        if (configJSONStr !== activeJSONStr) {
            logMessage('Updating dashboard layout...', 'info');
            rebuildDashboardLayout(pinsData);
            activePins = incomingConfig;
        }

        // 2. Perform element value updates (No flicker)
        incomingPinNums.forEach(pinNum => {
            const pinData = pinsData[pinNum];
            
            // A. Update Control Elements if applicable
            if (pinData.mode === 'control') {
                const toggleBtn = document.getElementById(`toggle-btn-${pinNum}`);
                const stateText = document.getElementById(`state-text-${pinNum}`);
                const relayStatus = pinData.status; // "ON" or "OFF"
                
                if (stateText && toggleBtn) {
                    stateText.textContent = relayStatus;
                    if (relayStatus === 'ON') {
                        stateText.className = 'status-value status-val-on';
                        toggleBtn.className = 'power-switch switch-on';
                    } else if (relayStatus === 'OFF') {
                        stateText.className = 'status-value status-val-off';
                        toggleBtn.className = 'power-switch switch-off';
                    } else {
                        stateText.className = 'status-value status-val-off';
                        toggleBtn.className = 'power-switch';
                    }
                }
            } else if (pinData.mode === 'momentary') {
                const toggleBtn = document.getElementById(`toggle-btn-${pinNum}`);
                const stateText = document.getElementById(`state-text-${pinNum}`);
                
                // If it's momentary, the static response state is almost always IDLE/OFF.
                // We only update if the button is not currently running a frontend pulsing animation.
                if (stateText && toggleBtn && !toggleBtn.classList.contains('pulsing')) {
                    stateText.textContent = 'IDLE';
                    stateText.className = 'status-value status-val-off';
                    toggleBtn.className = 'power-switch momentary-btn';
                }
            }

            // B. Update Monitor Elements
            const modeBadge = document.getElementById(`mode-badge-${pinNum}`);
            const levelBadge = document.getElementById(`level-badge-${pinNum}`);
            const rawText = document.getElementById(`raw-text-${pinNum}`);

            if (modeBadge) {
                const func = (pinData.func || 'unknown').toUpperCase();
                modeBadge.textContent = func;
                if (pinData.func === 'op') {
                    modeBadge.className = 'pin-badge mode-out';
                } else if (pinData.func === 'ip') {
                    modeBadge.className = 'pin-badge mode-in';
                } else {
                    modeBadge.className = 'pin-badge';
                }
            }

            if (levelBadge) {
                const lvl = pinData.level || 'unknown';
                if (lvl === 'hi') {
                    levelBadge.textContent = 'HIGH (3.3V)';
                    levelBadge.className = 'pin-level level-high';
                } else if (lvl === 'lo') {
                    levelBadge.textContent = 'LOW (0V)';
                    levelBadge.className = 'pin-level level-low';
                } else {
                    levelBadge.textContent = 'UNKNOWN';
                    levelBadge.className = 'pin-level level-unknown';
                }
            }

            if (rawText) {
                rawText.textContent = pinData.raw || 'No raw command response';
            }
        });
    }

    // Fetch Status from PHP backend
    async function fetchStatus(isSilent = false) {
        if (isStatusRequestPending) return;
        
        isStatusRequestPending = true;
        try {
            const response = await fetch(`${API_URL}?action=status`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const data = await response.json();
            updateDashboardUI(data);
            if (!isSilent) {
                logMessage(`Status fetched successfully.`, 'success');
            }
        } catch (error) {
            console.error('Fetch error:', error);
            connectionBadge.className = 'badge badge-loading';
            badgeText.textContent = 'Disconnected';
            logMessage(`API Connection Error: ${error.message}`, 'error');
        } finally {
            isStatusRequestPending = false;
        }
    }

    // Toggle specific GPIO Pin state (Latch mode)
    async function togglePin(pinNum) {
        if (pendingPins.has(pinNum)) return;
        
        pendingPins.add(pinNum);
        logMessage(`Toggling pin GPIO ${pinNum}...`, 'info');
        
        try {
            const response = await fetch(`${API_URL}?action=toggle`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ pin: pinNum })
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            const data = await response.json();
            if (data.success) {
                updateDashboardUI(data);
                const updatedPin = data.pins[pinNum];
                logMessage(`GPIO ${pinNum} (${updatedPin.name}) toggled: now ${updatedPin.status}`, 'success');
            } else {
                logMessage(`Toggle failed: ${data.error || 'Server error'}`, 'error');
            }
        } catch (error) {
            console.error('Toggle error:', error);
            logMessage(`Failed to send toggle command: ${error.message}`, 'error');
        } finally {
            pendingPins.delete(pinNum);
        }
    }

    // Pulse specific GPIO Pin state (Momentary mode)
    async function pulsePin(pinNum) {
        if (pendingPins.has(pinNum)) return;

        const toggleBtn = document.getElementById(`toggle-btn-${pinNum}`);
        const stateText  = document.getElementById(`state-text-${pinNum}`);

        // Find configured duration
        const pinConf  = activePins.find(p => p.pin === pinNum);
        const duration = pinConf ? parseInt(pinConf.pulse_duration) || 1000 : 1000;

        // Shared helper – safe to call multiple times (idempotent)
        const restoreIdleUI = () => {
            if (toggleBtn) {
                toggleBtn.classList.remove('pulsing');
                toggleBtn.className = 'power-switch momentary-btn';
            }
            if (stateText) {
                stateText.textContent = 'IDLE';
                stateText.className   = 'status-value status-val-off';
            }
        };

        // Mark only this pin as pulsing immediately.
        if (toggleBtn && stateText) {
            toggleBtn.classList.add('pulsing');
            stateText.textContent = 'PULSING...';
            stateText.className   = 'status-value status-val-on';
        }

        pendingPins.add(pinNum);
        logMessage(`Triggering momentary pulse on GPIO ${pinNum} for ${duration}ms...`, 'info');

        const fallbackTimer = setTimeout(() => {
            restoreIdleUI();
            pendingPins.delete(pinNum);
            logMessage(`GPIO ${pinNum} UI restored via fallback timer.`, 'warning');
            fetchStatus(true);
        }, duration + 1500);

        try {
            const response = await fetch(`${API_URL}?action=toggle`, {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ pin: pinNum })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            if (data.success) {
                logMessage(`GPIO ${pinNum} pulse started.`, 'success');
                updateDashboardUI(data);
                setTimeout(() => {
                    clearTimeout(fallbackTimer);
                    restoreIdleUI();
                    pendingPins.delete(pinNum);
                    fetchStatus(true);
                    logMessage(`GPIO ${pinNum} pulse sequence complete.`, 'success');
                }, duration);
            } else {
                clearTimeout(fallbackTimer);
                restoreIdleUI();
                pendingPins.delete(pinNum);
                logMessage(`Pulse failed: ${data.error || 'Server error'}`, 'error');
            }
        } catch (error) {
            clearTimeout(fallbackTimer);
            restoreIdleUI();
            pendingPins.delete(pinNum);
            logMessage(`Pulse error: ${error.message}`, 'error');
        }
    }

    // Helper to escape HTML characters
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Event Listeners
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
