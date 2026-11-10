CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 200),
    body TEXT NOT NULL CHECK (length(body) <= 2000),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version >= 1),
    client_key TEXT NOT NULL UNIQUE CHECK (length(client_key) BETWEEN 1 AND 64)
);
