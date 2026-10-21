"""
train_model.py
----------------------------------------------------------------
Trains the Logistic Regression model used by the system, using the
`students` table as historical data. Students with status = 'dropped'
are treated as the attrition-positive class (1); everyone else is 0.

Run this whenever the Registrar uploads a new historical dataset and
wants the model retrained:

    pip install pandas scikit-learn mysql-connector-python
    python train_model.py

It writes the new intercept/coefficients + accuracy straight into the
`model_settings` table, which prediction.php reads on every page load.
No need to touch the PHP code after retraining.
----------------------------------------------------------------
"""

import mysql.connector
import pandas as pd
from sklearn.linear_model import LogisticRegression
from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score

DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "ccs_attrition_system",
}


def load_data(conn):
    query = """
        SELECT gwa, attendance_rate, units_failed,
               CASE WHEN status = 'dropped' THEN 1 ELSE 0 END AS attrited
        FROM students
    """
    return pd.read_sql(query, conn)


def train(df):
    X = df[["gwa", "attendance_rate", "units_failed"]]
    y = df["attrited"]

    # Guard: need both classes present to train a meaningful model
    if y.nunique() < 2:
        raise ValueError(
            "Training data only has one class (e.g. no 'dropped' students yet). "
            "Add some historical dropped-student records before retraining."
        )

    X_train, X_test, y_train, y_test = train_test_split(
        X, y, test_size=0.2, random_state=42, stratify=y
    )

    model = LogisticRegression(max_iter=1000)
    model.fit(X_train, y_train)

    accuracy = accuracy_score(y_test, model.predict(X_test)) * 100

    intercept = model.intercept_[0]
    coef_gwa, coef_attendance, coef_units_failed = model.coef_[0]

    return intercept, coef_gwa, coef_attendance, coef_units_failed, accuracy


def save_settings(conn, intercept, coef_gwa, coef_attendance, coef_units_failed, accuracy):
    cursor = conn.cursor()
    updates = {
        "intercept": intercept,
        "coef_gwa": coef_gwa,
        "coef_attendance": coef_attendance,
        "coef_units_failed": coef_units_failed,
        "accuracy": round(accuracy, 2),
    }
    for key, value in updates.items():
        cursor.execute(
            """
            INSERT INTO model_settings (setting_key, setting_value)
            VALUES (%s, %s)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            """,
            (key, str(value)),
        )
    conn.commit()
    cursor.close()


def main():
    conn = mysql.connector.connect(**DB_CONFIG)
    try:
        df = load_data(conn)
        print(f"Loaded {len(df)} student records.")

        intercept, coef_gwa, coef_attendance, coef_units_failed, accuracy = train(df)

        print("Training complete:")
        print(f"  intercept          = {intercept:.4f}")
        print(f"  coef_gwa           = {coef_gwa:.4f}")
        print(f"  coef_attendance    = {coef_attendance:.4f}")
        print(f"  coef_units_failed  = {coef_units_failed:.4f}")
        print(f"  accuracy           = {accuracy:.2f}%")

        save_settings(conn, intercept, coef_gwa, coef_attendance, coef_units_failed, accuracy)
        print("model_settings table updated. prediction.php will use these values immediately.")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
