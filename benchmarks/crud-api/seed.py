#!/usr/bin/env python3
"""Deterministic SQLite seed shared by the Orku and Laravel CRUD fixtures."""
import argparse
import sqlite3
from pathlib import Path

ROOT = Path(__file__).resolve().parent
SEED_ROWS = 200


def seed_row(index: int) -> tuple[str, str, int, str]:
    return (f'Seed item {index:04d}', f'Seed body {index:04d}', 1, f'seed-{index:04d}')


def seed(connection: sqlite3.Connection, rows: int = SEED_ROWS, reset: bool = True) -> int:
    if type(rows) is not int or not 0 <= rows <= SEED_ROWS:
        raise ValueError(f'rows must be an integer between 0 and {SEED_ROWS}')
    with connection:
        if reset:
            connection.execute('DELETE FROM items')
            connection.execute("DELETE FROM sqlite_sequence WHERE name = 'items'")
        connection.executemany(
            'INSERT INTO items (title, body, version, client_key) VALUES (?, ?, ?, ?)',
            [seed_row(i) for i in range(1, rows + 1)],
        )
    return rows


def open_database(path: Path) -> sqlite3.Connection:
    connection = sqlite3.connect(path)
    connection.executescript((ROOT / 'schema.sql').read_text())
    return connection


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--db', type=Path, required=True)
    parser.add_argument('--rows', type=int, default=SEED_ROWS)
    parser.add_argument('--no-reset', action='store_true')
    args = parser.parse_args()
    args.db.parent.mkdir(parents=True, exist_ok=True)
    connection = open_database(args.db)
    try:
        print(seed(connection, args.rows, reset=not args.no_reset))
    finally:
        connection.close()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
