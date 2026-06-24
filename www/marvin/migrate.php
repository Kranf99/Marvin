<?php
// Run once: php migrate.php  (or visit from localhost to initialise new tables/columns)
date_default_timezone_set('Europe/Brussels');

$db = new SQLite3('../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
$db->exec('PRAGMA journal_mode=WAL;');

$steps = [];

// ── GlossaryChanges ──────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS GlossaryChanges (
    changeId        INTEGER PRIMARY KEY AUTOINCREMENT,
    rowId           INTEGER NOT NULL,
    name            TEXT,
    shortDescription TEXT,
    longDescription  TEXT,
    status          INTEGER,
    tags            TEXT,
    changedByUserId INTEGER NOT NULL,
    isSuperAdmin    INTEGER NOT NULL DEFAULT 0,
    changeStatus    TEXT    NOT NULL DEFAULT 'pending',
    createdAt       TEXT    NOT NULL,
    updatedAt       TEXT    NOT NULL,
    taskId          INTEGER
)");
$steps[] = 'GlossaryChanges table: ok';

$db->exec("CREATE INDEX IF NOT EXISTS idx_gc_pending  ON GlossaryChanges (rowId, changedByUserId, changeStatus, taskId, updatedAt)");
$steps[] = 'GlossaryChanges index: ok';

// ── AssetsChanges ────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS AssetsChanges (
    changeId        INTEGER PRIMARY KEY AUTOINCREMENT,
    rowId           INTEGER NOT NULL,
    name            TEXT,
    shortDescription TEXT,
    longDescription  TEXT,
    status          INTEGER,
    tags            TEXT,
    schema          TEXT,
    idserver        INTEGER,
    changedByUserId INTEGER NOT NULL,
    isSuperAdmin    INTEGER NOT NULL DEFAULT 0,
    changeStatus    TEXT    NOT NULL DEFAULT 'pending',
    createdAt       TEXT    NOT NULL,
    updatedAt       TEXT    NOT NULL,
    taskId          INTEGER
)");
$steps[] = 'AssetsChanges table: ok';

$db->exec("CREATE INDEX IF NOT EXISTS idx_ac_pending  ON AssetsChanges (rowId, changedByUserId, changeStatus, taskId, updatedAt)");
$steps[] = 'AssetsChanges index: ok';

// ── columnsChanges ───────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS columnsChanges (
    changeId        INTEGER PRIMARY KEY AUTOINCREMENT,
    rowId           INTEGER NOT NULL,
    name            TEXT,
    shortDescription TEXT,
    status          INTEGER,
    tags            TEXT,
    changedByUserId INTEGER NOT NULL,
    isSuperAdmin    INTEGER NOT NULL DEFAULT 0,
    changeStatus    TEXT    NOT NULL DEFAULT 'pending',
    createdAt       TEXT    NOT NULL,
    updatedAt       TEXT    NOT NULL,
    taskId          INTEGER
)");
$steps[] = 'columnsChanges table: ok';
$db->exec("CREATE INDEX IF NOT EXISTS idx_cc_pending ON columnsChanges (rowId, changedByUserId, changeStatus, taskId, updatedAt)");
$steps[] = 'columnsChanges index: ok';

// ── KPIChanges ───────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS KPIChanges (
    changeId        INTEGER PRIMARY KEY AUTOINCREMENT,
    rowId           INTEGER NOT NULL,
    name            TEXT,
    shortDescription TEXT,
    status          INTEGER,
    tags            TEXT,
    changedByUserId INTEGER NOT NULL,
    isSuperAdmin    INTEGER NOT NULL DEFAULT 0,
    changeStatus    TEXT    NOT NULL DEFAULT 'pending',
    createdAt       TEXT    NOT NULL,
    updatedAt       TEXT    NOT NULL,
    taskId          INTEGER
)");
$steps[] = 'KPIChanges table: ok';
$db->exec("CREATE INDEX IF NOT EXISTS idx_kc_pending ON KPIChanges (rowId, changedByUserId, changeStatus, taskId, updatedAt)");
$steps[] = 'KPIChanges index: ok';

// ── serversChanges ───────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS serversChanges (
    changeId        INTEGER PRIMARY KEY AUTOINCREMENT,
    rowId           INTEGER NOT NULL,
    name            TEXT,
    serverType      TEXT,
    description     TEXT,
    tags            TEXT,
    changedByUserId INTEGER NOT NULL,
    isSuperAdmin    INTEGER NOT NULL DEFAULT 0,
    changeStatus    TEXT    NOT NULL DEFAULT 'pending',
    createdAt       TEXT    NOT NULL,
    updatedAt       TEXT    NOT NULL,
    taskId          INTEGER
)");
$steps[] = 'serversChanges table: ok';
$db->exec("CREATE INDEX IF NOT EXISTS idx_sc_pending ON serversChanges (rowId, changedByUserId, changeStatus, taskId, updatedAt)");
$steps[] = 'serversChanges index: ok';

// ── workflowIOChanges ────────────────────────────────────────────────────────
// No rowId here — uses (idWorkflow, idIO) as the composite key.
// changeType is 'add' or 'remove'. Owner is Assets.idowner WHERE id=idWorkflow.
$db->exec("CREATE TABLE IF NOT EXISTS workflowIOChanges (
    changeId        INTEGER PRIMARY KEY AUTOINCREMENT,
    idWorkflow      INTEGER NOT NULL,
    idIO            INTEGER NOT NULL,
    isInput         INTEGER NOT NULL,
    changeType      TEXT    NOT NULL,
    changedByUserId INTEGER NOT NULL,
    isSuperAdmin    INTEGER NOT NULL DEFAULT 0,
    changeStatus    TEXT    NOT NULL DEFAULT 'pending',
    createdAt       TEXT    NOT NULL,
    updatedAt       TEXT    NOT NULL,
    taskId          INTEGER
)");
$steps[] = 'workflowIOChanges table: ok';
$db->exec("CREATE INDEX IF NOT EXISTS idx_wc_pending ON workflowIOChanges (idWorkflow, changedByUserId, changeStatus, taskId, updatedAt)");
$steps[] = 'workflowIOChanges index: ok';

// ── ReviewTasks ──────────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS ReviewTasks (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    taskType         TEXT NOT NULL,
    tableName        TEXT NOT NULL,
    rowId            INTEGER NOT NULL,
    assignedToUserId INTEGER NOT NULL,
    requestedByUserId INTEGER NOT NULL,
    status           TEXT NOT NULL DEFAULT 'pending',
    createdAt        TEXT NOT NULL,
    resolvedAt       TEXT
)");
$steps[] = 'ReviewTasks table: ok';

$db->exec("CREATE INDEX IF NOT EXISTS idx_rt_assigned ON ReviewTasks (assignedToUserId, status)");
$steps[] = 'ReviewTasks index: ok';

// ── SCD2 columns on Glossary ─────────────────────────────────────────────────
// SQLite has no ALTER TABLE IF NOT EXISTS COLUMN — catch errors individually
$scd2 = [
    "ALTER TABLE Glossary ADD COLUMN validityFrom TEXT",
    "ALTER TABLE Glossary ADD COLUMN validityTo   TEXT DEFAULT '99991231 23:59:59'",
    "ALTER TABLE Glossary ADD COLUMN isCurrentValue INTEGER DEFAULT 1",
    "ALTER TABLE Glossary ADD COLUMN changedByUserId INTEGER",
    "ALTER TABLE Assets   ADD COLUMN validityFrom TEXT",
    "ALTER TABLE Assets   ADD COLUMN validityTo   TEXT DEFAULT '99991231 23:59:59'",
    "ALTER TABLE Assets   ADD COLUMN isCurrentValue INTEGER DEFAULT 1",
    "ALTER TABLE Assets   ADD COLUMN changedByUserId INTEGER",
    "ALTER TABLE columns  ADD COLUMN validityFrom TEXT",
    "ALTER TABLE columns  ADD COLUMN validityTo   TEXT DEFAULT '99991231 23:59:59'",
    "ALTER TABLE columns  ADD COLUMN isCurrentValue INTEGER DEFAULT 1",
    "ALTER TABLE columns  ADD COLUMN changedByUserId INTEGER",
    "ALTER TABLE KPI      ADD COLUMN validityFrom TEXT",
    "ALTER TABLE KPI      ADD COLUMN validityTo   TEXT DEFAULT '99991231 23:59:59'",
    "ALTER TABLE KPI      ADD COLUMN isCurrentValue INTEGER DEFAULT 1",
    "ALTER TABLE KPI      ADD COLUMN changedByUserId INTEGER",
    "ALTER TABLE servers  ADD COLUMN validityFrom TEXT",
    "ALTER TABLE servers  ADD COLUMN validityTo   TEXT DEFAULT '99991231 23:59:59'",
    "ALTER TABLE servers  ADD COLUMN isCurrentValue INTEGER DEFAULT 1",
    "ALTER TABLE servers  ADD COLUMN changedByUserId INTEGER",
];
foreach ($scd2 as $sql) {
    try { $db->exec($sql); $steps[] = "$sql: ok"; }
    catch (Exception $e) { $steps[] = "$sql: skipped ({$e->getMessage()})"; }
}

// Initialise validity columns for existing rows
$db->exec("UPDATE Glossary SET validityFrom=dateUpdated, validityTo='99991231 23:59:59', isCurrentValue=1 WHERE validityFrom IS NULL");
$db->exec("UPDATE Assets   SET validityFrom=dateUpdated, validityTo='99991231 23:59:59', isCurrentValue=1 WHERE validityFrom IS NULL");
$db->exec("UPDATE columns  SET validityFrom=dateUpdated, validityTo='99991231 23:59:59', isCurrentValue=1 WHERE validityFrom IS NULL");
$db->exec("UPDATE KPI      SET validityFrom=dateUpdated, validityTo='99991231 23:59:59', isCurrentValue=1 WHERE validityFrom IS NULL");
$db->exec("UPDATE servers  SET validityFrom=dateUpdated, validityTo='99991231 23:59:59', isCurrentValue=1 WHERE validityFrom IS NULL");
$steps[] = 'SCD2 backfill: ok';

$db->close();

echo "<pre>" . implode("\n", $steps) . "\n\nMigration complete.</pre>";