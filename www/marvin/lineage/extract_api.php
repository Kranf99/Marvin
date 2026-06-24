<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$dbPath = __DIR__ . '/../../../db/lineage.sqlite';

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not found: ' . $dbPath]);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

try { switch ($action) {

    // ── List all .anatella files known to the DB ─────────────────────────
    case 'list_scripts':
        $stmt = $pdo->query(
            "SELECT DISTINCT File FROM Actions
             WHERE File IS NOT NULL AND File != ''
             ORDER BY File"
        );
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        break;

    // ── Extract info from one file (no DB write) ─────────────────────────
    case 'extract_script':
        $path = isset($_GET['path']) ? $_GET['path'] : '';
        if ($path === '') { http_response_code(400); echo json_encode(['error' => 'Missing path']); exit; }
        echo json_encode(extractFromAnatellaFile($path));
        break;

    // ── Save extraction result for one file (POST JSON body) ─────────────
    case 'save_script':
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['path'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid payload']);
            exit;
        }
        ensureTables($pdo);
        saveScriptData($pdo, $data);
        echo json_encode(['ok' => true, 'path' => $data['path']]);
        break;

    // ── Current DB schema summary ─────────────────────────────────────────
    case 'schema_info':
        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $info = [];
        foreach ($tables as $t) {
            $cols = $pdo->query("PRAGMA table_info(\"$t\")")->fetchAll(PDO::FETCH_ASSOC);
            $cnt  = $pdo->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn();
            $info[] = [
                'name'    => $t,
                'columns' => array_map(function($c) { return $c['name']; }, $cols),
                'rows'    => (int)$cnt
            ];
        }
        echo json_encode($info);
        break;

    // ── Return raw XML for a file (for pipeline visualizer) ──────────────
    case 'get_xml':
        $path = isset($_GET['path']) ? $_GET['path'] : '';
        if ($path === '') { http_response_code(400); echo json_encode(['error' => 'Missing path']); exit; }
        $fpath = str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!file_exists($fpath)) {
            http_response_code(404);
            echo json_encode(['error' => 'File not found: ' . $fpath]);
            exit;
        }
        $xml = file_get_contents($fpath);
        echo json_encode(['xml' => $xml, 'path' => str_replace('\\', '/', $fpath)]);
        break;

    // ── Open file in Anatella ─────────────────────────────────────────────
    case 'open_script':
        $path = isset($_GET['path']) ? $_GET['path'] : '';
        if ($path === '') { http_response_code(400); echo json_encode(['error' => 'Missing path']); exit; }
        $ANATELLA_EXE = 'C:\\soft\\TIMi\\bin\\Anatella.exe';
        $fpath = str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!file_exists($fpath)) { echo json_encode(['error' => 'File not found: ' . $fpath]); exit; }
        if (!file_exists($ANATELLA_EXE)) { echo json_encode(['error' => 'Anatella.exe not found']); exit; }
        $cmd = 'START "" /B "' . $ANATELLA_EXE . '" "' . $fpath . '"';
        pclose(popen($cmd, 'r'));
        echo json_encode(['ok' => true, 'path' => $fpath]);
        break;

    // ── Extraction stats (already-saved scripts) ──────────────────────────
    case 'extract_stats':
        $exists = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='ScriptMeta'"
        )->fetchColumn();
        if (!$exists) { echo json_encode(['total' => 0, 'done' => 0, 'errors' => 0]); break; }
        $total   = $pdo->query("SELECT COUNT(DISTINCT File) FROM Actions WHERE File IS NOT NULL AND File != ''")->fetchColumn();
        $done    = $pdo->query("SELECT COUNT(*) FROM ScriptMeta")->fetchColumn();
        $errors  = $pdo->query("SELECT COUNT(*) FROM ScriptMeta WHERE has_error=1")->fetchColumn();
        echo json_encode(['total' => (int)$total, 'done' => (int)$done, 'errors' => (int)$errors]);
        break;

    // ── Drop extracted tables (reset) ────────────────────────────────────
    case 'reset_extracted':
        $pdo->exec('DROP TABLE IF EXISTS ScriptIO');
        $pdo->exec('DROP TABLE IF EXISTS ScriptVarsExtracted');
        $pdo->exec('DROP TABLE IF EXISTS ScriptMeta');
        ensureTables($pdo);
        echo json_encode(['ok' => true]);
        break;

    // ── Recursively scan a directory for .anatella files ─────────────────
    case 'scan_dir':
        $root = isset($_GET['root']) ? $_GET['root'] : '';
        if ($root === '') { http_response_code(400); echo json_encode(['error' => 'Missing root']); exit; }
        $root = str_replace('/', DIRECTORY_SEPARATOR, $root);
        if (!is_dir($root)) {
            http_response_code(404);
            echo json_encode(['error' => 'Directory not found: ' . $root]);
            exit;
        }
        $files = [];
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $f) {
                if (strtolower($f->getExtension()) === 'anatella') {
                    $files[] = str_replace('\\', '/', $f->getPathname());
                }
            }
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        sort($files);
        echo json_encode($files);
        break;

    // ── Save a new datamart project entry ────────────────────────────────
    case 'save_datamart':
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        if (!is_array($data)) { http_response_code(400); echo json_encode(['error' => 'Invalid JSON']); exit; }
        $company  = isset($data['company'])   ? trim($data['company'])   : '';
        $client   = isset($data['client'])    ? trim($data['client'])    : '';
        $project  = isset($data['project'])   ? trim($data['project'])   : '';
        $datamart = isset($data['datamart'])  ? trim($data['datamart'])  : '';
        $rootDir  = isset($data['root_dir'])  ? trim($data['root_dir'])  : '';
        $repoName = isset($data['repo_name']) ? trim($data['repo_name']) : '';
        if ($company === '' || $project === '' || $datamart === '' || $rootDir === '') {
            http_response_code(400);
            echo json_encode(['error' => 'company, project, datamart and root_dir are required']);
            exit;
        }
        ensureDatamartTable($pdo);
        // INSERT OR IGNORE + UPDATE — compatible with all SQLite versions
        $pdo->prepare(
            'INSERT OR IGNORE INTO DatamartProjects (company, client, project, datamart, root_dir, repo_name)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$company, $client, $project, $datamart, $rootDir, $repoName]);
        $pdo->prepare(
            'UPDATE DatamartProjects SET root_dir=?, repo_name=?
             WHERE company=? AND client=? AND project=? AND datamart=?'
        )->execute([$rootDir, $repoName, $company, $client, $project, $datamart]);
        $row = $pdo->prepare(
            'SELECT id FROM DatamartProjects WHERE company=? AND client=? AND project=? AND datamart=?'
        );
        $row->execute([$company, $client, $project, $datamart]);
        $id = $row->fetchColumn();
        echo json_encode(['ok' => true, 'id' => (int)$id]);
        break;

    // ── List all datamart projects ────────────────────────────────────────
    case 'list_datamarts':
        ensureDatamartTable($pdo);
        $stmt = $pdo->query(
            'SELECT id, company, client, project, datamart, root_dir, repo_name, created_at
             FROM DatamartProjects ORDER BY company, client, project, datamart'
        );
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . $action]);
} } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ─── Core extraction ──────────────────────────────────────────────────────────

function extractFromAnatellaFile($rawPath) {
    $path = str_replace('/', DIRECTORY_SEPARATOR, $rawPath);
    $norm = str_replace('\\', '/', $rawPath);

    if (!file_exists($path)) {
        return ['error' => 'File not found', 'path' => $norm,
                'rtfl_count'=>0,'total_nodes'=>0,'connected_nodes'=>0,
                'inputs'=>[],'outputs'=>[],'called_scripts'=>[],'variables'=>[]];
    }

    $xmlStr = file_get_contents($path);
    if ($xmlStr === false) {
        return ['error' => 'Cannot read file', 'path' => $norm,
                'rtfl_count'=>0,'total_nodes'=>0,'connected_nodes'=>0,
                'inputs'=>[],'outputs'=>[],'called_scripts'=>[],'variables'=>[]];
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xmlStr);
    libxml_clear_errors();

    if (!$ok) {
        return ['error' => 'XML parse failed', 'path' => $norm,
                'rtfl_count'=>0,'total_nodes'=>0,'connected_nodes'=>0,
                'inputs'=>[],'outputs'=>[],'called_scripts'=>[],'variables'=>[]];
    }

    // ── Build node index ──────────────────────────────────────────────────
    $nodes = [];
    $actionsEl = $doc->getElementsByTagName('ACTIONS')->item(0);
    if (!$actionsEl) {
        return ['error' => 'No ACTIONS element', 'path' => $norm,
                'rtfl_count'=>0,'total_nodes'=>0,'connected_nodes'=>0,
                'inputs'=>[],'outputs'=>[],'called_scripts'=>[],'variables'=>[]];
    }
    foreach ($actionsEl->childNodes as $el) {
        if ($el->nodeType !== XML_ELEMENT_NODE) continue;
        $idx = (int)$el->getAttribute('idx');
        $nodes[$idx] = $el;
    }

    // ── Build reverse adjacency (dest → list of srcs) ────────────────────
    $revAdj = [];
    $connEl = $doc->getElementsByTagName('CONNECTORS')->item(0);
    if ($connEl) {
        foreach ($connEl->getElementsByTagName('Connection') as $c) {
            $src = (int)$c->getAttribute('idxSrc');
            $dst = (int)$c->getAttribute('idxDest');
            if (!isset($revAdj[$dst])) $revAdj[$dst] = [];
            $revAdj[$dst][] = $src;
        }
    }

    // ── Find RunToFinishLine nodes ────────────────────────────────────────
    $rtflIdxs = [];
    foreach ($nodes as $idx => $el) {
        if ($el->tagName === 'RunToFinishLine') $rtflIdxs[] = $idx;
    }

    // ── Backward BFS → all nodes that feed into a RunToFinishLine ────────
    $connected = [];
    $queue = $rtflIdxs;
    foreach ($rtflIdxs as $i) $connected[$i] = true;
    while (!empty($queue)) {
        $cur = array_shift($queue);
        foreach ((isset($revAdj[$cur]) ? $revAdj[$cur] : []) as $pred) {
            if (!isset($connected[$pred])) {
                $connected[$pred] = true;
                $queue[] = $pred;
            }
        }
    }

    // ── Extract information from connected nodes only ─────────────────────
    $readTags  = ['ReadColumnarGel','readGel','readCSV','readParquetFile',
                  'readExcel','readJSON','readHTTP','readSFTP'];
    $writeTags = ['writeColumnarGel','writeGel','writeCSV','writeParquetFile',
                  'writeExcel','writeJSON','writeSFTP'];

    $inputs        = [];
    $outputs       = [];
    $calledScripts = [];
    $variables     = [];

    foreach ($nodes as $idx => $el) {
        if (!isset($connected[$idx])) continue;
        $tag = $el->tagName;

        // ── File inputs ──────────────────────────────────────────────────
        if (in_array($tag, $readTags)) {
            $fn = $el->getAttribute('fileName');
            if ($fn === '') $fn = $el->getAttribute('file');
            if ($fn !== '') $inputs[] = ['idx'=>$idx,'type'=>$tag,'file'=>$fn,'is_query'=>false];

        } elseif ($tag === 'ReadOCI' || $tag === 'readOCI') {
            // OCI = Oracle DB read; try to get the table/query info
            $table = $el->getAttribute('table');
            $conn  = $el->getAttribute('odbc') ?: $el->getAttribute('connection');
            $qEl   = $el->getElementsByTagName('query')->item(0);
            $qText = $qEl ? trim($qEl->textContent) : '';
            $label = $conn ? $conn . '.' . $table : $table;
            if ($label === '') $label = $qText;
            if ($label !== '') $inputs[] = ['idx'=>$idx,'type'=>'ReadOCI','file'=>$label,'is_query'=>true];
        }

        // ── File outputs ─────────────────────────────────────────────────
        if (in_array($tag, $writeTags)) {
            $fn = $el->getAttribute('file');
            if ($fn === '') $fn = $el->getAttribute('fileName');
            if ($fn !== '') $outputs[] = ['idx'=>$idx,'type'=>$tag,'file'=>$fn];

        } elseif ($tag === 'WriteToDB' || $tag === 'writeOCI') {
            $tbl  = $el->getAttribute('table') ?: $el->getAttribute('tableName');
            $conn = $el->getAttribute('odbc') ?: $el->getAttribute('connection');
            $label = $conn ? $conn . '.' . $tbl : $tbl;
            if ($label !== '') $outputs[] = ['idx'=>$idx,'type'=>$tag,'file'=>$label];
        }

        // ── Called sub-scripts ───────────────────────────────────────────
        if ($tag === 'parallelRun' || $tag === 'callScript') {
            foreach ($el->getElementsByTagName('anatellaGraph') as $g) {
                $s = trim($g->textContent);
                if ($s !== '') $calledScripts[] = ['idx'=>$idx,'type'=>$tag,'script'=>$s];
            }
        }

        // ── Variable creations / updates (Calculator) ────────────────────
        if ($tag === 'Calculator' || $tag === 'CalculatorVectorized') {
            foreach ($el->getElementsByTagName('OutputVar') as $ov) {
                $name = $ov->getAttribute('name');
                $meta = $ov->getAttribute('meta');   // 'U'=update existing, ''=new
                $expr = trim($ov->textContent);
                if ($name !== '') {
                    $variables[] = [
                        'idx'        => $idx,
                        'type'       => 'Calculator',
                        'op'         => ($meta === 'U') ? 'update' : 'create',
                        'before'     => null,
                        'after'      => $name,
                        'expression' => $expr
                    ];
                }
            }
        }

        // ── Column renames ───────────────────────────────────────────────
        if ($tag === 'ColumnRename') {
            $befEl = $aftEl = null;
            foreach ($el->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                if ($child->tagName === 'before') $befEl = $child;
                if ($child->tagName === 'after')  $aftEl = $child;
            }
            if ($befEl && $aftEl) {
                $bCols = []; $aCols = [];
                foreach ($befEl->getElementsByTagName('c') as $c) $bCols[] = $c->textContent;
                foreach ($aftEl->getElementsByTagName('c') as $c) $aCols[] = $c->textContent;
                $cnt = max(count($bCols), count($aCols));
                for ($i = 0; $i < $cnt; $i++) {
                    $b = isset($bCols[$i]) ? $bCols[$i] : '';
                    $a = isset($aCols[$i]) ? $aCols[$i] : '';
                    if ($b !== $a && ($b !== '' || $a !== '')) {
                        $variables[] = [
                            'idx'        => $idx,
                            'type'       => 'ColumnRename',
                            'op'         => 'rename',
                            'before'     => $b !== '' ? $b : null,
                            'after'      => $a !== '' ? $a : $b,
                            'expression' => null
                        ];
                    }
                }
            }
        }
    }

    // Serialize ACTIONS + CONNECTORS for offline pipeline reconstruction
    $pipelineXml = '<pipeline>';
    if ($actionsEl) $pipelineXml .= $doc->saveXML($actionsEl);
    $connEl2 = $doc->getElementsByTagName('CONNECTORS')->item(0);
    if ($connEl2) $pipelineXml .= $doc->saveXML($connEl2);
    $pipelineXml .= '</pipeline>';

    return [
        'path'            => $norm,
        'ok'              => true,
        'rtfl_count'      => count($rtflIdxs),
        'total_nodes'     => count($nodes),
        'connected_nodes' => count($connected),
        'inputs'          => $inputs,
        'outputs'         => $outputs,
        'called_scripts'  => $calledScripts,
        'variables'       => $variables,
        'pipeline_xml'    => $pipelineXml,
    ];
}

// ─── DB persistence ───────────────────────────────────────────────────────────

function ensureDatamartTable($pdo) {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS DatamartProjects (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            company    TEXT    NOT NULL,
            client     TEXT    NOT NULL DEFAULT "",
            project    TEXT    NOT NULL,
            datamart   TEXT    NOT NULL,
            root_dir   TEXT    NOT NULL,
            repo_name  TEXT    NOT NULL DEFAULT "",
            created_at TEXT    DEFAULT (datetime(\'now\')),
            UNIQUE(company, client, project, datamart)
        )
    ');
}

function ensureTables($pdo) {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS "ScriptIO" (
            "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
            "script_path" TEXT    NOT NULL,
            "direction"   TEXT    NOT NULL,
            "file_path"   TEXT,
            "action_tag"  TEXT,
            "node_idx"    INTEGER,
            "is_db_query" INTEGER DEFAULT 0,
            "extracted_at" TEXT   DEFAULT (datetime(\'now\'))
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS "ScriptVarsExtracted" (
            "id"          INTEGER PRIMARY KEY AUTOINCREMENT,
            "script_path" TEXT    NOT NULL,
            "node_idx"    INTEGER,
            "action_tag"  TEXT,
            "op"          TEXT,
            "var_before"  TEXT,
            "var_after"   TEXT,
            "expression"  TEXT,
            "extracted_at" TEXT   DEFAULT (datetime(\'now\'))
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS "ScriptMeta" (
            "script_path"      TEXT PRIMARY KEY,
            "rtfl_count"       INTEGER DEFAULT 0,
            "total_nodes"      INTEGER DEFAULT 0,
            "connected_nodes"  INTEGER DEFAULT 0,
            "input_count"      INTEGER DEFAULT 0,
            "output_count"     INTEGER DEFAULT 0,
            "var_count"        INTEGER DEFAULT 0,
            "call_count"       INTEGER DEFAULT 0,
            "has_error"        INTEGER DEFAULT 0,
            "error_msg"        TEXT,
            "pipeline_xml"     TEXT,
            "extracted_at"     TEXT    DEFAULT (datetime(\'now\'))
        )
    ');
    // Migration: add column to existing DBs (no-op if already present)
    try { $pdo->exec('ALTER TABLE ScriptMeta ADD COLUMN pipeline_xml TEXT'); } catch (Exception $e) {}
}

function saveScriptData($pdo, $data) {
    $path = $data['path'];

    // Clear previous data for this script
    foreach (['ScriptIO', 'ScriptVarsExtracted', 'ScriptMeta'] as $tbl) {
        $pdo->prepare("DELETE FROM \"$tbl\" WHERE script_path = ?")->execute([$path]);
    }

    $hasError = (isset($data['error']) && $data['error']) ? 1 : 0;
    $errMsg   = $hasError ? $data['error'] : null;

    $pdo->prepare(
        'INSERT INTO ScriptMeta
         (script_path,rtfl_count,total_nodes,connected_nodes,input_count,output_count,var_count,call_count,has_error,error_msg,pipeline_xml)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $path,
        isset($data['rtfl_count'])      ? (int)$data['rtfl_count']      : 0,
        isset($data['total_nodes'])     ? (int)$data['total_nodes']     : 0,
        isset($data['connected_nodes']) ? (int)$data['connected_nodes'] : 0,
        isset($data['inputs'])          ? count($data['inputs'])         : 0,
        isset($data['outputs'])         ? count($data['outputs'])        : 0,
        isset($data['variables'])       ? count($data['variables'])      : 0,
        isset($data['called_scripts'])  ? count($data['called_scripts']) : 0,
        $hasError,
        $errMsg,
        isset($data['pipeline_xml'])    ? $data['pipeline_xml']          : null,
    ]);

    if ($hasError) return;

    $stmtIO = $pdo->prepare(
        'INSERT INTO ScriptIO (script_path,direction,file_path,action_tag,node_idx,is_db_query)
         VALUES (?,?,?,?,?,?)'
    );
    foreach ((isset($data['inputs']) ? $data['inputs'] : []) as $r) {
        $stmtIO->execute([$path, 'input', $r['file'], $r['type'], $r['idx'], $r['is_query'] ? 1 : 0]);
    }
    foreach ((isset($data['outputs']) ? $data['outputs'] : []) as $r) {
        $stmtIO->execute([$path, 'output', $r['file'], $r['type'], $r['idx'], 0]);
    }
    foreach ((isset($data['called_scripts']) ? $data['called_scripts'] : []) as $r) {
        $stmtIO->execute([$path, 'call', $r['script'], $r['type'], $r['idx'], 0]);
    }

    $stmtVar = $pdo->prepare(
        'INSERT INTO ScriptVarsExtracted (script_path,node_idx,action_tag,op,var_before,var_after,expression)
         VALUES (?,?,?,?,?,?,?)'
    );
    foreach ((isset($data['variables']) ? $data['variables'] : []) as $v) {
        if (!$v['after']) continue;
        $stmtVar->execute([$path, $v['idx'], $v['type'], $v['op'], $v['before'], $v['after'], $v['expression']]);
    }
}
