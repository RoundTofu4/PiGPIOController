# PiRelay - Raspberry Pi GPIO Relay Controller

A sleek, responsive, glassmorphic dark-theme web dashboard for controlling GPIO and monitoring status pins of a Raspberry Pi 3B.

The application automatically runs in **Mock Mode** when hosted on a laptop/local machine where `pinctrl` is not available, writing fake states to `mock_state.json`. When deployed to a Raspberry Pi, it uses the official `pinctrl` command-line utility.

---

## Features

- **Responsive Control Panel**: Works seamlessly on phone screens, tablets, and desktops.
- **Glassmorphic Theme**: A modern dark user interface with glowing CSS visual indicators.
- **Real-Time Monitoring**: Periodic polling of pin levels.
- **Diagnostics Log**: Interactive terminal panel demonstrating the PHP API queries and executions.
- **Double-Safety Relays**: Standardized for Active-LOW relays (low trigger: `op dl` turns on, `op dh` turns off).

---

## 1. Running Locally (Testing on Laptop)

To test the application on your laptop (which will run in **Mock Mode**):

1. Open a terminal in the folder containing these files.
2. Start the built-in PHP development server:
   ```bash
   php -S localhost:8000
   ```
3. Open your browser and navigate to:
   ```
   http://localhost:8000
   ```
4. Click the power button to toggle the mock relay state. You will see activity logged in the UI's Console Log.

---

## 2. Deploying on Raspberry Pi 3B

### Step 1: Install Nginx and PHP-FPM
Log into your Raspberry Pi terminal and run:
```bash
sudo apt update
sudo apt install -y nginx php-fpm php-cli
```

### Step 2: Copy Files
Move the workspace files to the default web directory on your Pi:
```bash
sudo mkdir -p /var/www/html/pirelay
sudo cp -r * /var/www/html/pirelay/
```
Ensure correct ownership:
```bash
sudo chown -R www-data:www-data /var/www/html/pirelay
```

### Step 3: Configure GPIO Permissions (CRITICAL)
By default, the web server user (`www-data`) does not have permission to control hardware pins. 

**Option A: Add to the `gpio` group (Recommended)**
Modern Raspberry Pi OS allows users in the `gpio` group to run `pinctrl`. Add the Nginx web user to this group:
```bash
sudo usermod -a -G gpio www-data
```
For the group changes to take effect, you **must restart the PHP-FPM service**:
```bash
# Check your PHP version (e.g. php8.2) and restart its FPM service:
sudo systemctl restart php8.2-fpm
# Or simply restart the system:
sudo reboot
```

**Option B: Sudoers Configuration (Fallback)**
If your system requires root privileges for `pinctrl`, configure passwordless sudo for the command:
1. Open the sudoers configuration:
   ```bash
   sudo visudo
   ```
2. Add this line at the bottom:
   ```text
   www-data ALL=(ALL) NOPASSWD: /usr/bin/pinctrl
   ```
3. Open `/var/www/html/pirelay/api.php` and change the configuration constant at the top:
   ```php
   define('PINCTRL_CMD', 'sudo pinctrl');
   ```

### Step 4: Configure Nginx Site
Create an Nginx configuration file or modify the default one to serve PHP:
```bash
sudo nano /etc/nginx/sites-available/pirelay
```
Insert the following configuration block:
```nginx
server {
    listen 80;
    server_name _; # Or use your Pi's IP address (e.g., 192.168.1.100)

    root /var/www/html/pirelay;
    index index.php index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Adjust PHP-FPM version if needed
    }
}
```
Enable the site and reload Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/pirelay /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default # Remove default site configuration if conflicting
sudo nginx -t
sudo systemctl restart nginx
```

Access the dashboard by navigating to your Raspberry Pi's IP address in your browser.
