# Smart Parking NSW Web App

A professional PHP web platform for monitoring NSW parking occupancy using live and historical data.

This repository combines:
- A PHP/MySQL dashboard (Home, Dashboard, Facilities, Insights, About)
- A MySQL data layer for facilities, occupancy snapshots, and model metrics
- A Python collector that can pull live data from the NSW Car Park API into MySQL

## Features

- Professional responsive UI across all pages
- Live KPI dashboard with charts and facility status table
- Facility search and selected-facility occupancy timeline
- Insights page with utilization trends and model metrics
- JSON API summary endpoint at `api/live_summary.php`
- Auto-refresh on live pages (every 60 seconds)

## Tech Stack

- PHP (XAMPP/Apache)
- MySQL (phpMyAdmin)
- Python 3
- Chart.js

## Project Structure

```text
smart-parking-php-live/
|- index.php
|- dashboard.php
|- facilities.php
|- insights.php
|- about.php
|- api/
|  |- live_summary.php
|- assets/
|  |- css/style.css
|  |- js/app.js
|- includes/
|  |- config.php
|  |- db.php
|  |- functions.php
|  |- header.php
|  |- footer.php
|- database/
|  |- smart_parking_web.sql
|- python/
|  |- live_to_mysql.py
|  |- requirements.txt
|  |- run_live_collector.bat
|- data/
|  |- parking_cleaned.csv
|  |- parking_processed.csv
```

## Local Setup (XAMPP)

1. Place the project in your XAMPP htdocs directory.

```text
C:\xampp\htdocs\smart-parking-php-live
```

2. Start `Apache` and `MySQL` in XAMPP.

3. Import the database schema/data in phpMyAdmin:

```text
database/smart_parking_web.sql
```

4. Confirm database settings in `includes/config.php`:
- host: `127.0.0.1`
- port: `3306`
- database: `smart_parking_web`
- user: `root`

5. Open the app:

```text
http://localhost/smart-parking-php-live/
```

## Optional: Enable Live Data Collection

If you want live NSW updates, configure and run the Python collector.

1. Edit `python/.env` with your real API key and MySQL settings:

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

2. Install Python dependencies:

```bash
pip install -r python/requirements.txt
```

3. Run one collection cycle:

```bash
python python/live_to_mysql.py --once
```

4. Run continuously every 300 seconds:

```bash
python python/live_to_mysql.py --loop 300
```

Alternative (Windows):

```bat
python\run_live_collector.bat
```

Collector logs are written to:

```text
logs/live_to_mysql.log
```

## API Endpoint

The app exposes a JSON summary endpoint:

```text
http://localhost/smart-parking-php-live/api/live_summary.php
```

Response includes:
- `summary`
- `dataset`
- `latest`
- `hourly`
- `distribution`

## Notes

- `python/.env` is ignored by git (not committed).
- The website can still run without live collection by using imported database data.
- Live ingestion is handled by Python; PHP reads from MySQL and renders the UI.
