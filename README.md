# Smart Parking Live NSW

Smart Parking Live NSW is a PHP/MySQL smart parking web application for monitoring NSW park-and-ride occupancy, analysing historical demand patterns, and showing short-horizon parking predictions.

The project combines live NSW parking data, MySQL storage, XGBoost model outputs, and official Sydney event context into one locally reproducible website.

GitHub repository:

```text
https://github.com/EliasSol100/CSE_473-Project
```

## Current Features

- Home page with a user-facing overview of the platform and monitored network.
- Dashboard page with live KPIs, hourly utilisation, availability distribution, most utilised facilities, and +1h, +2h, +3h predicted free-space totals.
- Facilities page with search, status filtering, sorting, selected-facility drill-down, latest status, and occupancy timeline.
- Insights page with utilisation trends and XGBoost regression/classification performance summaries.
- Events page with a maintained Sydney event feed, category filtering, event labels, and event-aware parking pressure.
- Event Forecast page for one selected event, showing nearby facilities within roughly 10 km and short-range event-day forecasts.
- About page written for end users, explaining what the platform helps people do.
- Dark/light mode toggle in the header.
- Automatic live refresh through PHP endpoints while the relevant pages are open.
- SQL seed fallback so the website can still be demonstrated when the live collector is not active.

## Data Sources

- NSW Car Park API for live parking facility and occupancy data.
- Official public Sydney event sources such as City of Sydney What's On, Qudos Bank Arena, and Sydney Olympic Park venue calendars.
- MySQL database for stored facility records, occupancy snapshots, model runs, metrics, predictions, and fallback seed data.

Only one private API key is required for the live parking feed:

```env
NSW_API_KEY=your_real_key_here
```

The event sources currently use public official pages and do not require private API keys.

## Tech Stack

- PHP with XAMPP/Apache
- MySQL with phpMyAdmin
- Python 3 for the optional collector and XGBoost training path
- XGBoost, pandas, scikit-learn, PyMySQL
- Chart.js for charts
- HTML, CSS, and JavaScript for the frontend

## Project Structure

```text
smart-parking-live/
|- index.php
|- dashboard.php
|- facilities.php
|- insights.php
|- events.php
|- event_forecasts.php
|- about.php
|- api/
|  |- collect_live.php
|  |- live_summary.php
|  |- home_summary.php
|  |- facilities_summary.php
|  |- insights_summary.php
|  |- events_summary.php
|  |- about_summary.php
|- assets/
|  |- css/style.css
|  |- js/app.js
|- includes/
|  |- config.php
|  |- db.php
|  |- functions.php
|  |- live_collector.php
|  |- ml_model.php
|  |- event_live_sources.php
|  |- event_forecast_engine.php
|  |- page_payloads.php
|  |- header.php
|  |- footer.php
|- database/
|  |- smart_parking_web.sql
|- python/
|  |- live_to_mysql.py
|  |- train_xgboost.py
|  |- requirements.txt
|  |- run_live_collector.bat
```

Generated runtime files such as logs, local environment files, caches, and model artifacts are not committed.

## Local Setup With XAMPP

1. Place the project in the XAMPP `htdocs` directory.

```text
C:\xampp\htdocs\smart-parking-live
```

2. Start Apache and MySQL from the XAMPP control panel.

3. Import the database file in phpMyAdmin.

```text
database/smart_parking_web.sql
```

This creates the database schema and imports fallback seed data for local demonstration.

4. Confirm the database settings in `includes/config.php`.

```text
host: 127.0.0.1
port: 3306
database: smart_parking_web
user: root
password:
```

5. Create or update `python/.env` with the live API key and MySQL settings.

```env
NSW_API_KEY=your_real_key_here
MYSQL_HOST=127.0.0.1
MYSQL_PORT=3306
MYSQL_DB=smart_parking_web
MYSQL_USER=root
MYSQL_PASSWORD=
```

6. Open the website.

```text
http://localhost/smart-parking-live/
```

## Live Collector

The website can trigger live parking syncs through:

```text
api/collect_live.php
```

The PHP collector:

- reads the NSW API key from `.env` or `python/.env`
- pulls current parking data from the NSW Car Park API
- writes fresh rows into MySQL with `snapshot_source = live`
- rate-limits collection so refreshes do not run too aggressively
- updates the website through AJAX without requiring a full page reload
- can trigger an XGBoost refresh check after successful live syncs

Useful optional environment values:

```env
DASHBOARD_COLLECT_INTERVAL_SECONDS=10
DASHBOARD_REQUEST_TIMEOUT_SECONDS=20
DASHBOARD_MAX_PARALLEL_REQUESTS=10
XGBOOST_TRAIN_INTERVAL_SECONDS=300
```

## SQL Seed Data and Live Data Behaviour

The SQL file is useful because it creates the database and provides seed data for demonstrations.

The website chooses the best available source:

- if the live collector is active and live rows exist, pages use `snapshot_source = live`
- if the live collector is not active, pages can fall back to `snapshot_source = seed`

This means the project can still be opened and demonstrated after importing the SQL file, while the live collector remains the preferred path during normal use.

Historical-only facilities are kept in the database for reference/fallback context, but hidden from live-facing views and excluded from XGBoost training.

## XGBoost Forecasting

The project uses XGBoost for short-horizon parking prediction.

The training script is:

```text
python/train_xgboost.py
```

It trains:

- an XGBoost regression model for future occupancy rate and predicted available spaces
- an XGBoost classification model for future status class: Available, Limited, or Full

Predictions are stored in MySQL for:

- +1 hour
- +2 hours
- +3 hours

Model evidence is stored in:

```text
model_runs
facility_metrics
predictions
model_artifacts
```

The Insights page displays regression metrics such as MAE, RMSE, and R2, plus classification accuracy by facility.

## Optional Python Collector and Training Setup

Install Python dependencies:

```bash
pip install -r python/requirements.txt
```

Run one live collection cycle:

```bash
python python/live_to_mysql.py --once
```

Run the optional collector continuously:

```bash
python python/live_to_mysql.py --loop 300
```

Train or refresh XGBoost manually:

```bash
python python/train_xgboost.py --source live --json
```

For fallback seed data:

```bash
python python/train_xgboost.py --source seed --json
```

Windows helper:

```bat
python\run_live_collector.bat
```

## JSON Endpoints

The frontend uses these endpoints for live page summaries:

```text
api/collect_live.php
api/live_summary.php
api/home_summary.php
api/facilities_summary.php
api/insights_summary.php
api/events_summary.php
api/about_summary.php
```

Typical responses include summary metrics, latest facility rows, hourly charts, status distribution, model predictions, and event forecast payloads.

## Notes

- `python/.env` is ignored by Git and must not be committed.
- `logs/`, local caches, temporary files, and generated model artifacts are ignored.
- Full status is shown only when available spaces are zero.
- Limited status is used for high occupancy when spaces still remain.
- Event forecasts are short-range estimates and should be interpreted as decision support, not guaranteed availability.
- The repository includes the website source code, database import file, documentation, and local installation instructions.
