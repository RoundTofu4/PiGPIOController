<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PiRelay - Raspberry Pi GPIO Controller</title>
    
    <!-- Google Fonts for Premium Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="style.css">
    <script src="script.js" defer></script>
</head>
<body>
    <div class="glass-bg-decor decor-1"></div>
    <div class="glass-bg-decor decor-2"></div>
    
    <div class="container">
        <!-- Header Section -->
        <header class="app-header">
            <div class="logo-area">
                <div class="pulse-ring"></div>
                <h1>PiRelay <span class="version">v2.0</span></h1>
            </div>
            <div class="header-controls">
                <div id="connection-badge" class="badge badge-loading">
                    <span class="badge-dot"></span>
                    <span class="badge-text">Connecting...</span>
                </div>
                <button id="open-settings-btn" class="btn-settings" title="Configuration Settings">
                    <svg viewBox="0 0 24 24" width="20" height="20">
                        <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                        <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- Main Dashboard -->
        <main class="dashboard-grid">
            
            <!-- Relay Controls Container (Dynamic list of control switches) -->
            <div id="controls-section" class="controls-wrapper">
                <!-- Dynamically populated control cards will go here -->
                <div id="controls-loader" class="card loading-card">
                    <p>Loading relay controls...</p>
                </div>
            </div>

            <!-- GPIO Monitoring Card -->
            <section class="card monitor-card">
                <div class="card-header">
                    <h2>Pin Status Monitor</h2>
                    <button id="refresh-btn" class="btn-refresh" title="Refresh Status">
                        <svg viewBox="0 0 24 24" width="16" height="16">
                            <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
                        </svg>
                    </button>
                </div>
                
                <!-- Monitor list dynamically populated -->
                <div class="monitor-body" id="monitor-list-container">
                    <p class="empty-state">Loading status list...</p>
                </div>
            </section>

            <!-- Console Log Output -->
            <section class="card console-card">
                <div class="card-header">
                    <h2>Activity Log</h2>
                    <button id="clear-log-btn" class="btn-clear">Clear</button>
                </div>
                <div class="console-body" id="console-log">
                    <div class="log-line log-info">System initialized. Loading configuration...</div>
                </div>
            </section>

        </main>
        
        <footer class="app-footer">
            <p>Designed for Raspberry Pi 3B &bull; Built with PHP &amp; Vanilla JS</p>
        </footer>
    </div>

    <!-- Settings Modal -->
    <div id="settings-modal" class="modal-backdrop">
        <div class="modal-content glass">
            <div class="modal-header">
                <h2>GPIO Pin Configuration</h2>
                <button id="close-settings-btn" class="btn-close" title="Close Settings">&times;</button>
            </div>
            
            <div class="modal-body">
                <!-- Active Pins List -->
                <section class="settings-section">
                    <h3>Active GPIO Pins</h3>
                    <div class="pins-config-list" id="pins-config-list">
                        <!-- Populated by script -->
                    </div>
                </section>
                
                <!-- Add Pin Form -->
                <section class="settings-section">
                    <h3>Add New GPIO Pin</h3>
                    <form id="add-pin-form" class="settings-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new-pin-num">GPIO Pin Number</label>
                                <input type="number" id="new-pin-num" min="1" max="40" placeholder="e.g. 22" required>
                            </div>
                            <div class="form-group">
                                <label for="new-pin-name">Custom Label</label>
                                <input type="text" id="new-pin-name" placeholder="e.g. Lampu Teras" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="new-pin-mode">Pin Mode</label>
                                <select id="new-pin-mode" required>
                                    <option value="control">Control (Relay Switch)</option>
                                    <option value="monitor">Monitor Only</option>
                                </select>
                            </div>
                            <div class="form-group form-checkbox-group" id="active-low-group">
                                <label class="checkbox-container">
                                    <input type="checkbox" id="new-pin-active-low" checked>
                                    <span class="checkbox-label">Active LOW Trigger (LOW = ON)</span>
                                </label>
                            </div>
                        </div>
                        <button type="submit" class="btn-add-pin">Add Pin to Dashboard</button>
                    </form>
                </section>
            </div>
            
            <div class="modal-footer">
                <button id="cancel-settings-btn" class="btn-cancel">Close</button>
                <button id="save-settings-btn" class="btn-save">Save Configuration</button>
            </div>
        </div>
    </div>
</body>
</html>
