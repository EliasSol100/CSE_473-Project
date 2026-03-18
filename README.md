# Smart Parking Live Web Application

## Architecture

- **PHP + XAMPP**: presentation layer
- **MySQL / phpMyAdmin**: website database
- **Python collector**: fetches live NSW car park data and writes snapshots into MySQL
- **Historical data**: can remain in the database so the website still has demo content before live collection starts

## Run locally

### 1. Put the project in XAMPP
Copy the folder into:

```text
C:\xampp\htdocs\smart-parking-php-live
```

### 2. Start XAMPP
Start:
- Apache
- MySQL

### 3. Import the database
Open phpMyAdmin and import:

```text
database/smart_parking_web.sql
```

This gives you the website tables plus demo/historical content.

### 4. Open the website
Go to:

```text
http://localhost/smart-parking-php-live/
```

## Enable live mode

### 1. Get your NSW API key
Use the same API key you already used in the Python project.

### 2. Create the Python env file
Inside the `python` folder:
- copy `.env.example`
- rename it to `.env`
- fill in your real values

Example:

```env
NSW_API_KEY=your_real_key_here
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_DB=smart_parking_web
MYSQL_USER=root
MYSQL_PASSWORD=
COLLECT_INTERVAL_SECONDS=300
REQUEST_SLEEP=0.15
```

### 3. Install Python dependencies
From the project root or the `python` folder:

```bash
pip install -r python/requirements.txt
```

### 4. Run one live collection cycle

```bash
python python/live_to_mysql.py --once
```

### 5. Run the continuous live collector

```bash
python python/live_to_mysql.py --loop 300
```

That means a new live collection every **300 seconds (5 minutes)**.

## What is live and what is not

- The **website** is PHP and reads the newest rows from MySQL.
- The **live part** comes from the Python collector, not from PHP alone.
- The pages auto-refresh every **60 seconds** so newly inserted snapshots appear without manual reload.
- The demo SQL import contains historical data too, so the site still looks complete before live collection starts.

## Important explanation for the teacher

A correct academic explanation is:

> The frontend is implemented as a simple PHP/MySQL web application, while the backend live-data ingestion is performed by Python scripts that poll the NSW Car Park API and update the MySQL database used by the website.

## Project pages

- `index.php` – overview
- `dashboard.php` – live KPIs and charts
- `facilities.php` – latest facility table and per-facility timeline
- `insights.php` – analysis and model metrics
- `about.php` – architecture explanation
- `api/live_summary.php` – JSON endpoint with current summary data
- `python/live_to_mysql.py` – live collector to MySQL

## GitHub tip

Upload the whole folder as a repository, but do **not** commit your real `python/.env` file.
Commit only `.env.example`.
