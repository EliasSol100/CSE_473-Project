from __future__ import annotations

import argparse
import json
import math
import os
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Iterable
from zoneinfo import ZoneInfo

import pandas as pd
import pymysql
from dotenv import load_dotenv
from sklearn.metrics import accuracy_score, mean_absolute_error, mean_squared_error, r2_score
from xgboost import XGBClassifier, XGBRegressor

ROOT = Path(__file__).resolve().parents[1]
LOGS_DIR = ROOT / "logs"
MODELS_DIR = ROOT / "python" / "models"
STATE_FILE = LOGS_DIR / "live_collector_state.json"
MODEL_NAME = "xgboost"
CLASS_LABELS = ["Available", "Limited", "Full"]
PARKING_TIMEZONE = ZoneInfo("Australia/Sydney")
BASE_FEATURE_COLUMNS = [
    "facility_id",
    "capacity",
    "occupied",
    "available",
    "occupancy_rate",
    "hour",
    "day_of_week",
    "is_weekend",
    "month",
    "hours_ahead",
    "target_hour",
    "target_day_of_week",
    "target_is_weekend",
    "target_month",
]
RATE_LEVEL_HISTORY_FEATURES = [
    "previous_occupancy_rate",
    "previous_2_occupancy_rate",
    "previous_3_occupancy_rate",
    "rolling_mean_3",
    "rolling_mean_6",
]
HISTORY_FEATURE_COLUMNS = [
    *RATE_LEVEL_HISTORY_FEATURES,
    "rate_change_1",
    "rate_change_2",
    "rolling_std_3",
    "rolling_std_6",
    "minutes_since_previous",
    "hour_sin",
    "hour_cos",
]
R2_MIN_TARGET_STD = 0.015


def load_environment() -> None:
    for candidate in (ROOT / ".env", ROOT / "python" / ".env"):
        if candidate.exists():
            load_dotenv(candidate, override=False)


def collector_recently_active(max_age_seconds: int = 180) -> bool:
    if not STATE_FILE.exists():
        return False

    try:
        state = json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except Exception:
        return False

    last_completed_ts = int(state.get("last_completed_at_ts") or 0)
    if last_completed_ts <= 0:
        return False

    return (datetime.now(timezone.utc).timestamp() - last_completed_ts) <= max(30, max_age_seconds)


def connect_db():
    return pymysql.connect(
        host=os.getenv("MYSQL_HOST", "127.0.0.1"),
        port=int(os.getenv("MYSQL_PORT", "3306")),
        user=os.getenv("MYSQL_USER", "root"),
        password=os.getenv("MYSQL_PASSWORD", ""),
        database=os.getenv("MYSQL_DB", "smart_parking_web"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )


def choose_source(connection, requested: str) -> str:
    requested = requested.strip().lower()
    if requested in {"live", "seed"}:
        return requested

    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT "
            "SUM(CASE WHEN snapshot_source = 'live' THEN 1 ELSE 0 END) AS live_rows, "
            "SUM(CASE WHEN snapshot_source = 'seed' THEN 1 ELSE 0 END) AS seed_rows "
            "FROM occupancy_snapshots"
        )
        row = cursor.fetchone() or {}

    live_rows = int(row.get("live_rows") or 0)
    seed_rows = int(row.get("seed_rows") or 0)

    if collector_recently_active() and live_rows > 0:
        return "live"

    return "seed" if seed_rows > 0 else ("live" if live_rows > 0 else "seed")


def availability_class(available: int | None, occupancy_rate: float) -> str:
    if available is not None and int(available) <= 0:
        return "Full"
    if float(occupancy_rate) >= 0.70:
        return "Limited"
    return "Available"


def add_history_features(history: pd.DataFrame) -> pd.DataFrame:
    if history.empty:
        return history

    frame = history.sort_values(["facility_id", "recorded_at"]).copy()
    grouped = frame.groupby("facility_id", sort=False)
    occupancy = grouped["occupancy_rate"]

    frame["previous_occupancy_rate"] = occupancy.shift(1)
    frame["previous_2_occupancy_rate"] = occupancy.shift(2)
    frame["previous_3_occupancy_rate"] = occupancy.shift(3)
    frame["rate_change_1"] = frame["occupancy_rate"] - frame["previous_occupancy_rate"]
    frame["rate_change_2"] = frame["previous_occupancy_rate"] - frame["previous_2_occupancy_rate"]
    frame["rolling_mean_3"] = occupancy.transform(lambda series: series.shift(1).rolling(3, min_periods=1).mean())
    frame["rolling_std_3"] = occupancy.transform(lambda series: series.shift(1).rolling(3, min_periods=2).std())
    frame["rolling_mean_6"] = occupancy.transform(lambda series: series.shift(1).rolling(6, min_periods=1).mean())
    frame["rolling_std_6"] = occupancy.transform(lambda series: series.shift(1).rolling(6, min_periods=2).std())
    frame["minutes_since_previous"] = grouped["recorded_at"].diff().dt.total_seconds().div(60).clip(lower=0, upper=1440)
    frame["hour_sin"] = frame["hour"].astype(float).map(lambda hour: math.sin((2 * math.pi * hour) / 24))
    frame["hour_cos"] = frame["hour"].astype(float).map(lambda hour: math.cos((2 * math.pi * hour) / 24))

    for column in RATE_LEVEL_HISTORY_FEATURES:
        frame[column] = frame[column].fillna(frame["occupancy_rate"])

    for column in ("rate_change_1", "rate_change_2", "rolling_std_3", "rolling_std_6", "minutes_since_previous"):
        frame[column] = frame[column].fillna(0)

    return frame


def fetch_snapshot_history(connection, snapshot_source: str) -> pd.DataFrame:
    sql = """
        SELECT
            s.facility_id,
            f.facility_name,
            f.capacity,
            s.recorded_at,
            s.occupied,
            s.available,
            s.occupancy_rate,
            s.hour,
            s.day_of_week,
            s.is_weekend,
            s.month
        FROM occupancy_snapshots s
        INNER JOIN parking_facilities f ON f.facility_id = s.facility_id
        WHERE s.snapshot_source = %s
          AND LOWER(f.facility_name) NOT LIKE '%%historical only%%'
        ORDER BY s.facility_id ASC, s.recorded_at ASC
    """

    with connection.cursor() as cursor:
        cursor.execute(sql, (snapshot_source,))
        rows = cursor.fetchall()

    frame = pd.DataFrame(rows)
    if frame.empty:
        return frame

    frame["recorded_at"] = pd.to_datetime(frame["recorded_at"], utc=False)
    frame["capacity"] = frame["capacity"].astype(int)
    frame["occupied"] = frame["occupied"].astype(int)
    frame["available"] = frame["available"].astype(int)
    frame["occupancy_rate"] = frame["occupancy_rate"].astype(float)
    frame["hour"] = frame["hour"].astype(int)
    frame["day_of_week"] = frame["day_of_week"].astype(int)
    frame["is_weekend"] = frame["is_weekend"].astype(int)
    frame["month"] = frame["month"].astype(int)
    return add_history_features(frame)


def nearest_future_index(times_ns, target_ns: int, max_diff_ns: int) -> int | None:
    import numpy as np

    position = int(np.searchsorted(times_ns, target_ns, side="left"))
    candidates: list[int] = []
    if position < len(times_ns):
        candidates.append(position)
    if position > 0:
        candidates.append(position - 1)

    best_index = None
    best_distance = None
    for candidate in candidates:
        candidate_ns = int(times_ns[candidate])
        if candidate_ns <= target_ns - max_diff_ns:
            continue
        distance = abs(candidate_ns - target_ns)
        if distance > max_diff_ns:
            continue
        if best_distance is None or distance < best_distance:
            best_distance = distance
            best_index = candidate

    return best_index


def build_training_examples(history: pd.DataFrame) -> pd.DataFrame:
    import numpy as np

    examples: list[dict] = []
    max_diff_ns = int(pd.Timedelta(minutes=30).value)
    horizons = (1, 2, 3)

    for facility_id, group in history.groupby("facility_id", sort=False):
        group = group.sort_values("recorded_at").reset_index(drop=True)
        times_ns = group["recorded_at"].to_numpy(dtype="datetime64[ns]").astype("int64")

        for index, row in group.iterrows():
            source_time_ns = int(times_ns[index])
            source_time = row["recorded_at"]

            for hours_ahead in horizons:
                target_ns = source_time_ns + int(pd.Timedelta(hours=hours_ahead).value)
                target_index = nearest_future_index(times_ns, target_ns, max_diff_ns)
                if target_index is None:
                    continue

                target_row = group.iloc[target_index]
                target_time = target_row["recorded_at"]
                if target_time <= source_time:
                    continue

                example = {
                    "facility_id": str(facility_id),
                    "facility_name": row["facility_name"],
                    "capacity": int(row["capacity"]),
                    "occupied": int(row["occupied"]),
                    "available": int(row["available"]),
                    "occupancy_rate": float(row["occupancy_rate"]),
                    "hour": int(row["hour"]),
                    "day_of_week": int(row["day_of_week"]),
                    "is_weekend": int(row["is_weekend"]),
                    "month": int(row["month"]),
                    "hours_ahead": int(hours_ahead),
                    "target_hour": int(target_row["hour"]),
                    "target_day_of_week": int(target_row["day_of_week"]),
                    "target_is_weekend": int(target_row["is_weekend"]),
                    "target_month": int(target_row["month"]),
                    "source_time": source_time,
                    "target_time": target_time,
                    "target_rate": float(target_row["occupancy_rate"]),
                    "target_available": int(target_row["available"]),
                    "target_class": availability_class(int(target_row["available"]), float(target_row["occupancy_rate"])),
                }

                for column in HISTORY_FEATURE_COLUMNS:
                    example[column] = float(row[column])

                examples.append(example)

    return pd.DataFrame(examples)


def prepare_features(examples: pd.DataFrame) -> tuple[pd.DataFrame, list[str]]:
    feature_frame = examples[[*BASE_FEATURE_COLUMNS, *HISTORY_FEATURE_COLUMNS]].copy()

    feature_frame = pd.get_dummies(feature_frame, columns=["facility_id"], prefix="facility", dtype=int)
    feature_columns = list(feature_frame.columns)
    return feature_frame, feature_columns


def split_train_test(examples: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame]:
    ordered = examples.sort_values("source_time").reset_index(drop=True)
    if len(ordered) < 20:
        raise RuntimeError("Not enough historical rows to train XGBoost reliably yet.")

    split_index = max(1, int(len(ordered) * 0.8))
    if split_index >= len(ordered):
        split_index = len(ordered) - 1

    train_frame = ordered.iloc[:split_index].reset_index(drop=True)
    test_frame = ordered.iloc[split_index:].reset_index(drop=True)
    return train_frame, test_frame


def align_columns(frame: pd.DataFrame, feature_columns: Iterable[str]) -> pd.DataFrame:
    aligned = frame.copy()
    for column in feature_columns:
        if column not in aligned.columns:
            aligned[column] = 0
    aligned = aligned[list(feature_columns)]
    return aligned


def train_models(train_examples: pd.DataFrame, feature_columns: list[str]):
    regressor = XGBRegressor(
        n_estimators=450,
        max_depth=3,
        learning_rate=0.03,
        subsample=0.85,
        colsample_bytree=0.85,
        reg_lambda=2.0,
        reg_alpha=0.05,
        objective="reg:squarederror",
        random_state=42,
        n_jobs=1,
    )

    classifier = XGBClassifier(
        n_estimators=220,
        max_depth=6,
        learning_rate=0.05,
        subsample=0.9,
        colsample_bytree=0.9,
        objective="multi:softprob",
        num_class=len(CLASS_LABELS),
        eval_metric="mlogloss",
        random_state=42,
        n_jobs=1,
    )

    X_train_raw, _ = prepare_features(train_examples)
    X_train = align_columns(X_train_raw, feature_columns)
    y_train_reg = train_examples["target_rate"].astype(float)
    y_train_cls = train_examples["target_class"].map({label: index for index, label in enumerate(CLASS_LABELS)}).astype(int)

    regressor.fit(X_train, y_train_reg)
    classifier.fit(X_train, y_train_cls)
    return regressor, classifier


def compute_per_facility_metrics(test_examples: pd.DataFrame, regression_predictions, classification_predictions) -> pd.DataFrame:
    metrics_rows: list[dict] = []
    test_frame = test_examples.copy()
    test_frame["predicted_rate"] = regression_predictions
    test_frame["predicted_class"] = [CLASS_LABELS[int(value)] for value in classification_predictions]

    for facility_name, group in test_frame.groupby("facility_name", sort=True):
        actual_rate = group["target_rate"].astype(float)
        predicted_rate = group["predicted_rate"].astype(float)
        actual_class = group["target_class"].astype(str)
        predicted_class = group["predicted_class"].astype(str)
        sample_size = int(len(group))

        mae = float(mean_absolute_error(actual_rate, predicted_rate))
        rmse = float(math.sqrt(mean_squared_error(actual_rate, predicted_rate)))
        r2 = None
        if sample_size >= 2 and actual_rate.nunique() >= 2 and float(actual_rate.std(ddof=0)) >= R2_MIN_TARGET_STD:
            r2 = float(r2_score(actual_rate, predicted_rate))
        accuracy = float(accuracy_score(actual_class, predicted_class))

        metrics_rows.append(
            {
                "facility_id": str(group.iloc[0]["facility_id"]),
                "facility_name": facility_name,
                "sample_size": sample_size,
                "mae": mae,
                "rmse": rmse,
                "r2": r2,
                "accuracy": accuracy,
            }
        )

    return pd.DataFrame(metrics_rows)


def latest_feature_rows(history: pd.DataFrame) -> pd.DataFrame:
    latest = history.sort_values("recorded_at").groupby("facility_id", sort=False).tail(1).copy()
    latest = latest.reset_index(drop=True)
    return latest


def build_prediction_rows(latest_rows: pd.DataFrame, feature_columns: list[str], regressor, classifier) -> pd.DataFrame:
    prediction_rows: list[dict] = []
    now = datetime.now(PARKING_TIMEZONE)

    for _, row in latest_rows.iterrows():
        for hours_ahead in (1, 2, 3):
            target_time = (now + timedelta(hours=hours_ahead)).replace(minute=0, second=0, microsecond=0)
            feature_data = {
                "facility_id": str(row["facility_id"]),
                "capacity": int(row["capacity"]),
                "occupied": int(row["occupied"]),
                "available": int(row["available"]),
                "occupancy_rate": float(row["occupancy_rate"]),
                "hour": int(row["hour"]),
                "day_of_week": int(row["day_of_week"]),
                "is_weekend": int(row["is_weekend"]),
                "month": int(row["month"]),
                "hours_ahead": hours_ahead,
                "target_hour": int(target_time.hour),
                "target_day_of_week": int(target_time.weekday()),
                "target_is_weekend": 1 if target_time.weekday() >= 5 else 0,
                "target_month": int(target_time.month),
            }

            for column in HISTORY_FEATURE_COLUMNS:
                fallback = float(row["occupancy_rate"]) if column in RATE_LEVEL_HISTORY_FEATURES else 0.0
                value = row.get(column, fallback)
                feature_data[column] = fallback if pd.isna(value) else float(value)

            feature_row = pd.DataFrame([feature_data])

            encoded = pd.get_dummies(feature_row, columns=["facility_id"], prefix="facility", dtype=int)
            aligned = align_columns(encoded, feature_columns)
            predicted_rate = float(max(0.0, min(1.0, regressor.predict(aligned)[0])))
            predicted_class_index = int(classifier.predict(aligned)[0])
            predicted_occupied = int(round(predicted_rate * int(row["capacity"])))
            predicted_occupied = max(0, min(int(row["capacity"]), predicted_occupied))
            predicted_available = max(0, int(row["capacity"]) - predicted_occupied)
            predicted_class = availability_class(predicted_available, predicted_rate)

            prediction_rows.append(
                {
                    "facility_id": str(row["facility_id"]),
                    "hours_ahead": hours_ahead,
                    "target_time": target_time.replace(tzinfo=None).strftime("%Y-%m-%d %H:%M:%S"),
                    "predicted_occupancy_rate": predicted_rate,
                    "predicted_occupied": predicted_occupied,
                    "predicted_available": predicted_available,
                    "predicted_class": predicted_class,
                    "classification_model_class": CLASS_LABELS[predicted_class_index],
                }
            )

    return pd.DataFrame(prediction_rows)


def persist_run(
    connection,
    snapshot_source: str,
    feature_columns: list[str],
    train_examples: pd.DataFrame,
    test_examples: pd.DataFrame,
    metrics: pd.DataFrame,
    predictions: pd.DataFrame,
    regressor,
    classifier,
) -> int:
    MODELS_DIR.mkdir(parents=True, exist_ok=True)
    timestamp = datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    reg_path = MODELS_DIR / f"xgboost_{snapshot_source}_regressor_{timestamp}.json"
    cls_path = MODELS_DIR / f"xgboost_{snapshot_source}_classifier_{timestamp}.json"
    features_path = MODELS_DIR / f"xgboost_{snapshot_source}_features_{timestamp}.json"

    regressor.save_model(reg_path)
    classifier.save_model(cls_path)
    features_path.write_text(json.dumps(feature_columns, ensure_ascii=False, indent=2), encoding="utf-8")

    notes = "Unified XGBoost regression/classification models trained for +1h, +2h, and +3h occupancy forecasting with recent-history occupancy features."

    with connection.cursor() as cursor:
        cursor.execute(
            """
            INSERT INTO model_runs
                (model_name, snapshot_source, run_status, trained_at, training_rows, validation_rows, feature_count, notes)
            VALUES (%s, %s, 'completed', UTC_TIMESTAMP(), %s, %s, %s, %s)
            """,
            (MODEL_NAME, snapshot_source, int(len(train_examples)), int(len(test_examples)), int(len(feature_columns)), notes),
        )
        run_id = int(cursor.lastrowid)

        if not metrics.empty:
            metric_rows = [
                (
                    run_id,
                    str(row["facility_id"]),
                    int(row["sample_size"]),
                    None if pd.isna(row["mae"]) else float(row["mae"]),
                    None if pd.isna(row["rmse"]) else float(row["rmse"]),
                    None if pd.isna(row["r2"]) else float(row["r2"]),
                    None if pd.isna(row["accuracy"]) else float(row["accuracy"]),
                )
                for _, row in metrics.iterrows()
            ]
            cursor.executemany(
                """
                INSERT INTO facility_metrics
                    (run_id, facility_id, sample_size, mae, rmse, r2, accuracy, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, UTC_TIMESTAMP())
                """,
                metric_rows,
            )

        if not predictions.empty:
            prediction_rows = [
                (
                    run_id,
                    str(row["facility_id"]),
                    int(row["hours_ahead"]),
                    str(row["target_time"]),
                    float(row["predicted_occupancy_rate"]),
                    int(row["predicted_occupied"]),
                    int(row["predicted_available"]),
                    str(row["predicted_class"]),
                )
                for _, row in predictions.iterrows()
            ]
            cursor.executemany(
                """
                INSERT INTO predictions
                    (run_id, facility_id, hours_ahead, target_time, predicted_occupancy_rate, predicted_occupied, predicted_available, predicted_class, created_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, UTC_TIMESTAMP())
                """,
                prediction_rows,
            )

        cursor.executemany(
            """
            INSERT INTO model_artifacts (run_id, artifact_type, horizon_hours, file_path, created_at)
            VALUES (%s, %s, %s, %s, UTC_TIMESTAMP())
            """,
            [
                (run_id, "regressor", None, str(reg_path.relative_to(ROOT)).replace("\\", "/")),
                (run_id, "classifier", None, str(cls_path.relative_to(ROOT)).replace("\\", "/")),
                (run_id, "features", None, str(features_path.relative_to(ROOT)).replace("\\", "/")),
            ],
        )

    connection.commit()
    return run_id


def train(snapshot_source: str) -> dict:
    connection = connect_db()
    try:
        history = fetch_snapshot_history(connection, snapshot_source)
        if history.empty:
            raise RuntimeError(f"No {snapshot_source} occupancy history is available for training.")

        examples = build_training_examples(history)
        if examples.empty or len(examples) < 50:
            raise RuntimeError(f"Not enough {snapshot_source} training examples to fit XGBoost reliably yet.")

        train_examples, test_examples = split_train_test(examples)
        feature_frame, feature_columns = prepare_features(examples)
        regressor, classifier = train_models(train_examples, feature_columns)

        X_test_raw, _ = prepare_features(test_examples)
        X_test = align_columns(X_test_raw, feature_columns)
        regression_predictions = regressor.predict(X_test)
        regression_predictions = regression_predictions.clip(0, 1)
        classification_predictions = classifier.predict(X_test)

        metrics = compute_per_facility_metrics(test_examples, regression_predictions, classification_predictions)
        latest_rows = latest_feature_rows(history)
        predictions = build_prediction_rows(latest_rows, feature_columns, regressor, classifier)
        run_id = persist_run(
            connection,
            snapshot_source,
            feature_columns,
            train_examples,
            test_examples,
            metrics,
            predictions,
            regressor,
            classifier,
        )

        overall_rmse = float(math.sqrt(mean_squared_error(test_examples["target_rate"], regression_predictions)))
        overall_accuracy = float(accuracy_score(test_examples["target_class"], [CLASS_LABELS[int(value)] for value in classification_predictions]))

        return {
            "status": "completed",
            "model_name": MODEL_NAME,
            "snapshot_source": snapshot_source,
            "run_id": run_id,
            "training_rows": int(len(train_examples)),
            "validation_rows": int(len(test_examples)),
            "feature_count": int(len(feature_columns)),
            "facility_count": int(latest_rows["facility_id"].nunique()),
            "overall_rmse": round(overall_rmse, 6),
            "overall_accuracy": round(overall_accuracy, 6),
        }
    finally:
        connection.close()


def main() -> int:
    parser = argparse.ArgumentParser(description="Train XGBoost parking forecast models and persist predictions to MySQL.")
    parser.add_argument("--source", default="auto", choices=["auto", "live", "seed"], help="Which snapshot source to train on.")
    parser.add_argument("--json", action="store_true", help="Print machine-readable JSON output.")
    parser.add_argument("--quiet", action="store_true", help="Reduce console output.")
    args = parser.parse_args()

    load_environment()
    connection = connect_db()
    try:
        snapshot_source = choose_source(connection, args.source)
    finally:
        connection.close()

    try:
        result = train(snapshot_source)
    except Exception as exc:
        payload = {
            "status": "error",
            "model_name": MODEL_NAME,
            "snapshot_source": snapshot_source,
            "message": str(exc),
        }
        if args.json:
            print(json.dumps(payload, ensure_ascii=False))
        else:
            print(f"[xgboost] {payload['message']}")
        return 1

    if args.json:
        print(json.dumps(result, ensure_ascii=False))
    elif not args.quiet:
        print(
            f"[xgboost] trained {result['model_name']} on {result['snapshot_source']} data "
            f"(run {result['run_id']}, rmse={result['overall_rmse']:.4f}, accuracy={result['overall_accuracy']:.4f})"
        )

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
