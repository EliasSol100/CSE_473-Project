from __future__ import annotations

import argparse
import json
import os
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import requests

try:
    import pymysql
except ImportError:  # pragma: no cover
    pymysql = None

try:
    from dotenv import load_dotenv
except ImportError:  # pragma: no cover
    load_dotenv = None

ROOT = Path(__file__).resolve().parents[1]
ENV_PATH = ROOT / "python" / ".env"
LOG_DIR = ROOT / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)

BASE_URL = "https://api.transport.nsw.gov.au/v1"
LIST_URL = f"{BASE_URL}/carpark"
DETAIL_URL = f"{BASE_URL}/carpark?facility={{facility_id}}"
DEFAULT_INTERVAL = 300
DEFAULT_REQUEST_SLEEP = 0.15
TIMEOUT_SECONDS = 30

# Facilities that the list endpoint returns, but the detail endpoint does not serve.
# They will be skipped after the first failed attempt in the current collector session.
KNOWN_UNAVAILABLE_FACILITIES: set[str] = set()


@dataclass
class Config:
    api_key: str
    mysql_host: str
    mysql_port: int
    mysql_db: str
    mysql_user: str
    mysql_password: str
    interval_seconds: int = DEFAULT_INTERVAL
    request_sleep: float = DEFAULT_REQUEST_SLEEP


def log(message: str) -> None:
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{timestamp}] {message}"
    print(line)
    with open(LOG_DIR / "live_to_mysql.log", "a", encoding="utf-8") as fh:
        fh.write(line + "\n")


def load_env() -> None:
    if load_dotenv and ENV_PATH.exists():
        load_dotenv(ENV_PATH)
        return

    if not ENV_PATH.exists():
        return

    for line in ENV_PATH.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


def get_config() -> Config:
    load_env()

    api_key = os.getenv("NSW_API_KEY", "").strip()
    if not api_key:
        raise RuntimeError("Missing NSW_API_KEY. Add it to python/.env first.")

    return Config(
        api_key=api_key,
        mysql_host=os.getenv("MYSQL_HOST", "127.0.0.1"),
        mysql_port=int(os.getenv("MYSQL_PORT", "3306")),
        mysql_db=os.getenv("MYSQL_DB", "smart_parking_web"),
        mysql_user=os.getenv("MYSQL_USER", "root"),
        mysql_password=os.getenv("MYSQL_PASSWORD", ""),
        interval_seconds=int(os.getenv("COLLECT_INTERVAL_SECONDS", str(DEFAULT_INTERVAL))),
        request_sleep=float(os.getenv("REQUEST_SLEEP", str(DEFAULT_REQUEST_SLEEP))),
    )


def mysql_connection(cfg: Config):
    if pymysql is None:
        raise RuntimeError("Missing dependency 'pymysql'. Run: pip install -r python/requirements.txt")

    return pymysql.connect(
        host=cfg.mysql_host,
        port=cfg.mysql_port,
        user=cfg.mysql_user,
        password=cfg.mysql_password,
        database=cfg.mysql_db,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )


def headers(cfg: Config) -> dict[str, str]:
    return {
        "Accept": "application/json",
        "Authorization": f"apikey {cfg.api_key}",
    }


def request_json(url: str, cfg: Config, *, quiet_404: bool = False) -> dict[str, Any] | None:
    try:
        response = requests.get(url, headers=headers(cfg), timeout=TIMEOUT_SECONDS)
    except requests.RequestException as exc:
        log(f"Request failed for {url} :: {exc}")
        return None

    if response.status_code == 404 and quiet_404:
        return None

    if response.status_code != 200:
        log(f"HTTP {response.status_code} for {url} :: {response.text[:250]}")
        return None

    try:
        return response.json()
    except ValueError:
        log(f"Invalid JSON response for {url}")
        return None


def parse_int(value: Any) -> int | None:
    if value is None:
        return None
    if isinstance(value, bool):
        return int(value)
    if isinstance(value, (int, float)):
        return int(value)
    if isinstance(value, str):
        cleaned = value.strip().replace(",", "")
        if cleaned.isdigit() or (cleaned.startswith("-") and cleaned[1:].isdigit()):
            return int(cleaned)
    return None


def parse_float(value: Any) -> float | None:
    if value is None:
        return None
    if isinstance(value, (int, float)):
        return float(value)
    if isinstance(value, str):
        cleaned = value.strip().replace(",", "")
        try:
            return float(cleaned)
        except ValueError:
            return None
    return None


def parse_timestamp(payload: dict[str, Any]) -> datetime:
    candidates = [
        payload.get("MessageDate"),
        payload.get("messageDate"),
        payload.get("last_updated"),
    ]

    for value in candidates:
        if not value:
            continue
        try:
            text = str(value).replace("Z", "+00:00")
            dt = datetime.fromisoformat(text)
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=timezone.utc)
            return dt.astimezone(timezone.utc)
        except ValueError:
            continue

    return datetime.now(timezone.utc)


def availability_class(rate: float, available: int) -> str:
    if available <= 0:
        return "Full"
    if rate >= 0.70:
        return "Limited"
    return "Available"


def extract_fields(payload: dict[str, Any]) -> dict[str, Any] | None:
    if not isinstance(payload, dict):
        return None

    facility_id = str(payload.get("facility_id") or "").strip()
    facility_name = str(payload.get("facility_name") or "").strip()
    capacity = parse_int(payload.get("spots"))

    occupancy = payload.get("occupancy") if isinstance(payload.get("occupancy"), dict) else {}
    occupied = parse_int(occupancy.get("total"))

    location = payload.get("location") if isinstance(payload.get("location"), dict) else {}
    latitude = parse_float(location.get("latitude"))
    longitude = parse_float(location.get("longitude"))

    if not facility_id or capacity is None or occupied is None:
        return None

    available = max(capacity - occupied, 0)
    occupancy_rate = min(max(occupied / capacity if capacity else 0.0, 0.0), 1.0)
    observed_at = parse_timestamp(payload)

    return {
        "facility_id": facility_id,
        "facility_name": facility_name or facility_id,
        "capacity": capacity,
        "occupied": occupied,
        "available": available,
        "occupancy_rate": occupancy_rate,
        "availability_class": availability_class(occupancy_rate, available),
        "recorded_at": observed_at,
        "hour": observed_at.hour,
        "day_of_week": observed_at.weekday(),
        "is_weekend": 1 if observed_at.weekday() >= 5 else 0,
        "month": observed_at.month,
        "latitude": latitude,
        "longitude": longitude,
        "raw_json": json.dumps(payload, ensure_ascii=False),
    }


def upsert_facility(cur, item: dict[str, Any]) -> None:
    cur.execute(
        """
        INSERT INTO parking_facilities (facility_id, facility_name, latitude, longitude, capacity)
        VALUES (%s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            facility_name = VALUES(facility_name),
            latitude = COALESCE(VALUES(latitude), latitude),
            longitude = COALESCE(VALUES(longitude), longitude),
            capacity = COALESCE(VALUES(capacity), capacity)
        """,
        (
            item["facility_id"],
            item["facility_name"],
            item["latitude"],
            item["longitude"],
            item["capacity"],
        ),
    )


def insert_snapshot(cur, item: dict[str, Any]) -> None:
    cur.execute(
        """
        INSERT INTO occupancy_snapshots
            (facility_id, recorded_at, occupied, available, occupancy_rate, availability_class, hour, day_of_week, is_weekend, month)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            occupied = VALUES(occupied),
            available = VALUES(available),
            occupancy_rate = VALUES(occupancy_rate),
            availability_class = VALUES(availability_class),
            hour = VALUES(hour),
            day_of_week = VALUES(day_of_week),
            is_weekend = VALUES(is_weekend),
            month = VALUES(month)
        """,
        (
            item["facility_id"],
            item["recorded_at"].strftime("%Y-%m-%d %H:%M:%S"),
            item["occupied"],
            item["available"],
            item["occupancy_rate"],
            item["availability_class"],
            item["hour"],
            item["day_of_week"],
            item["is_weekend"],
            item["month"],
        ),
    )


def collect_once(cfg: Config) -> None:
    log("Requesting facility list from NSW Car Park API...")
    payload = request_json(LIST_URL, cfg)
    if not isinstance(payload, dict):
        raise RuntimeError("Failed to fetch facility list from the API.")

    all_facility_ids = [str(fid) for fid in payload.keys()]
    facility_ids = [fid for fid in all_facility_ids if fid not in KNOWN_UNAVAILABLE_FACILITIES]

    log(
        f"Facilities discovered: {len(all_facility_ids)} total, "
        f"{len(facility_ids)} queryable this cycle"
    )

    inserted = 0
    skipped = 0
    failures = 0
    unavailable_this_run: list[str] = []

    conn = mysql_connection(cfg)
    try:
        with conn.cursor() as cur:
            for fid in facility_ids:
                detail = request_json(DETAIL_URL.format(facility_id=fid), cfg, quiet_404=True)

                if not isinstance(detail, dict):
                    failures += 1
                    KNOWN_UNAVAILABLE_FACILITIES.add(fid)
                    unavailable_this_run.append(fid)
                    time.sleep(cfg.request_sleep)
                    continue

                item = extract_fields(detail)
                if item is None:
                    skipped += 1
                    time.sleep(cfg.request_sleep)
                    continue

                upsert_facility(cur, item)
                insert_snapshot(cur, item)
                inserted += 1
                time.sleep(cfg.request_sleep)

        conn.commit()

        if unavailable_this_run:
            log(f"Skipped unavailable facilities this run: {', '.join(unavailable_this_run)}")

        log(f"Run finished: inserted/updated={inserted}, skipped={skipped}, failures={failures}")
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def run_loop(cfg: Config, interval_seconds: int) -> None:
    log(f"Live collector started. Interval: {interval_seconds} seconds.")
    try:
        while True:
            started = time.time()
            try:
                collect_once(cfg)
            except Exception as exc:  # pragma: no cover
                log(f"Collector error: {exc}")

            elapsed = time.time() - started
            sleep_for = max(5, interval_seconds - int(elapsed))
            log(f"Sleeping for {sleep_for} seconds...")
            time.sleep(sleep_for)
    except KeyboardInterrupt:
        log("Collector stopped by user.")


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Fetch live NSW parking data into MySQL for the PHP website."
    )
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--once", action="store_true", help="Run a single collection cycle and exit.")
    group.add_argument(
        "--loop",
        type=int,
        metavar="SECONDS",
        help="Run forever with the given polling interval in seconds.",
    )
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        cfg = get_config()
        if args.loop:
            run_loop(cfg, args.loop)
        else:
            collect_once(cfg)
        return 0
    except Exception as exc:
        log(f"Fatal error: {exc}")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
