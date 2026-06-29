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
                <h1>PiRelay <span class="version">v1.0</span></h1>
            </div>
            <div id="connection-badge" class="badge badge-loading">
                <span class="badge-dot"></span>
                <span class="badge-text">Connecting...</span>
            </div>
        </header>

        <!-- Main Dashboard -->
        <main class="dashboard-grid">
            
            <!-- Relay Control Card -->
            <section class="card control-card">
                <div class="card-header">
                    <h2>Relay Control</h2>
                    <span class="pin-hint">GPIO 17</span>
                </div>
                
                <div class="control-body">
                    <!-- High-End Toggle Switch -->
                    <div class="power-switch-container">
                        <button id="relay-toggle-btn" class="power-switch" aria-label="Toggle Relay">
                            <div class="switch-inner">
                                <svg class="power-icon" viewBox="0 0 24 24">
                                    <path d="M12 2v10M18.36 5.64A9 9 0 1 1 5.64 5.64" />
                                </svg>
                            </div>
                        </button>
                    </div>

                    <div class="status-indicator">
                        <span class="status-label">Current State</span>
                        <div id="relay-state-text" class="status-value status-val-off">OFF</div>
                        <p class="trigger-hint">Low-level trigger (Active LOW)</p>
                    </div>
                </div>
            </section>

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
                
                <div class="monitor-body">
                    <!-- GPIO 17 Monitor Detail -->
                    <div class="pin-row" id="pin-17-row">
                        <div class="pin-meta">
                            <span class="pin-num">17</span>
                            <span class="pin-name">Relay Pin</span>
                        </div>
                        <div class="pin-data">
                            <span class="pin-badge mode-out">OUTPUT</span>
                            <span class="pin-level level-unknown">---</span>
                        </div>
                        <div class="raw-console">pinctrl get 17: <code id="raw-17">Loading...</code></div>
                    </div>

                    <!-- GPIO 18 Monitor Detail -->
                    <div class="pin-row" id="pin-18-row">
                        <div class="pin-meta">
                            <span class="pin-num">18</span>
                            <span class="pin-name">Feedback/Input</span>
                        </div>
                        <div class="pin-data">
                            <span class="pin-badge mode-in">INPUT</span>
                            <span class="pin-level level-unknown">---</span>
                        </div>
                        <div class="raw-console">pinctrl get 18: <code id="raw-18">Loading...</code></div>
                    </div>
                </div>
            </section>

            <!-- Console Log Output -->
            <section class="card console-card">
                <div class="card-header">
                    <h2>Activity Log</h2>
                    <button id="clear-log-btn" class="btn-clear">Clear</button>
                </div>
                <div class="console-body" id="console-log">
                    <div class="log-line log-info">System initialized. Awaiting API connection...</div>
                </div>
            </section>

        </main>
        
        <footer class="app-footer">
            <p>Designed for Raspberry Pi 3B &bull; Built with PHP &amp; Vanilla JS</p>
        </footer>
    </div>
</body>
</html>
