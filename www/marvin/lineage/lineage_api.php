<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$dbPath = __DIR__ . '/../../../db/lineage.sqlite';

if (!file_exists($dbPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database not found: ' . $dbPath]);
    exit;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot open database: ' . $e->getMessage()]);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

try {
switch ($action) {
    case 'datamarts':
        $dmExists = $pdo->query(
            "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='DatamartProjects'"
        )->fetchColumn();
        if (!$dmExists) { echo json_encode([]); break; }
        $stmt = $pdo->query(
            'SELECT id, company, client, project, datamart, root_dir, repo_name
             FROM DatamartProjects ORDER BY company, client, project, datamart'
        );
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'destinations':
    case 'sources':
        $dir    = $action === 'sources' ? 'input' : 'output';
        $dbOnly = $action === 'sources' ? " AND is_db_query=0" : '';
        $root   = isset($_GET['root']) ? rtrim(str_replace('\\', '/', $_GET['root']), '/') : '';
        $rows   = [];
        if ($pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptIO'")->fetchColumn()) {
            if ($root !== '') {
                $rootBwd = str_replace('/', '\\', $root);
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT file_path FROM ScriptIO
                     WHERE direction=? $dbOnly AND file_path IS NOT NULL AND file_path != ''
                       AND (script_path LIKE ? OR script_path LIKE ?)
                     ORDER BY file_path"
                );
                $stmt->execute([$dir, $root . '/%', $rootBwd . '\\%']);
            } else {
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT file_path FROM ScriptIO
                     WHERE direction=? $dbOnly AND file_path IS NOT NULL AND file_path != ''
                     ORDER BY file_path"
                );
                $stmt->execute([$dir]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode($rows);
        break;

    case 'scripts':
        $rows = [];
        if ($pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptMeta'")->fetchColumn()) {
            $rows = $pdo->query(
                "SELECT DISTINCT script_path FROM ScriptMeta
                 WHERE script_path IS NOT NULL AND script_path != ''
                 ORDER BY script_path"
            )->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode($rows);
        break;

    case 'graph_for_script':
        $script = isset($_GET['script']) ? $_GET['script'] : '';
        if ($script === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing script parameter']);
            exit;
        }
        $scriptFwd2 = str_replace('\\', '/', $script);
        $scriptBwd2 = str_replace('/', '\\', $script);
        $bn2 = basename($scriptFwd2);

        // Resolve canonical path from ScriptMeta
        $resolved = $scriptFwd2;
        $sm = $pdo->prepare("SELECT script_path FROM ScriptMeta WHERE script_path=? OR script_path=? LIMIT 1");
        $sm->execute([$scriptFwd2, $scriptBwd2]);
        $r = $sm->fetchColumn();
        if (!$r) {
            $sm2 = $pdo->prepare("SELECT script_path FROM ScriptMeta WHERE script_path LIKE ? OR script_path LIKE ? LIMIT 1");
            $sm2->execute(['%/' . $bn2, '%\\' . $bn2]);
            $r = $sm2->fetchColumn();
        }
        if ($r) $resolved = str_replace('\\', '/', $r);
        $resolvedBwd = str_replace('/', '\\', $resolved);

        // Build file-lineage rows from ScriptIO
        $depRows     = [];
        $rel_before  = [];
        $rel_after   = [];
        $seen_before = [];
        $seen_after  = [];

        $ioTblExists = $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptIO'")->fetchColumn();
        if ($ioTblExists) {
            $inStmt = $pdo->prepare(
                "SELECT DISTINCT file_path FROM ScriptIO
                 WHERE direction='input' AND is_db_query=0 AND (script_path=? OR script_path=?)
                 ORDER BY file_path"
            );
            $inStmt->execute([$resolved, $resolvedBwd]);
            $ins = $inStmt->fetchAll(PDO::FETCH_COLUMN);

            $dbInStmt = $pdo->prepare(
                "SELECT DISTINCT file_path FROM ScriptIO
                 WHERE direction='input' AND is_db_query=1 AND (script_path=? OR script_path=?)
                 ORDER BY file_path"
            );
            $dbInStmt->execute([$resolved, $resolvedBwd]);
            $dbIns = $dbInStmt->fetchAll(PDO::FETCH_COLUMN);

            $outStmt = $pdo->prepare(
                "SELECT DISTINCT file_path FROM ScriptIO
                 WHERE direction='output' AND (script_path=? OR script_path=?)
                 ORDER BY file_path"
            );
            $outStmt->execute([$resolved, $resolvedBwd]);
            $outs = $outStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($ins) && !empty($outs)) {
                // Normal: pair each input with each output through the focal script
                foreach ($ins as $in) {
                    foreach ($outs as $out) {
                        $depRows[] = ['source' => $in, 's0' => $resolved, 'destination' => $out];
                    }
                }
            } elseif (empty($ins)) {
                // Source script (generates files, reads none): focal is the source
                foreach ($outs as $out) {
                    $depRows[] = ['source' => $resolved, 'destination' => $out];
                }
            } else {
                // Sink script (consumes files, writes none): focal is the destination
                foreach ($ins as $in) {
                    $depRows[] = ['source' => $in, 'destination' => $resolved];
                }
            }

            // Scripts that call this script (upstream callers)
            $callerStmt = $pdo->prepare(
                "SELECT DISTINCT script_path FROM ScriptIO
                 WHERE direction='call' AND (file_path=? OR file_path=?)"
            );
            $callerStmt->execute([$resolved, $resolvedBwd]);
            foreach ($callerStmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $p = str_replace('\\', '/', $p);
                if (!isset($seen_before[$p])) { $seen_before[$p] = true; $rel_before[] = $p; }
            }

            // Scripts that this script calls (downstream sub-scripts)
            $calledStmt = $pdo->prepare(
                "SELECT DISTINCT file_path FROM ScriptIO
                 WHERE direction='call' AND file_path LIKE '%.anatella'
                   AND (script_path=? OR script_path=?)"
            );
            $calledStmt->execute([$resolved, $resolvedBwd]);
            foreach ($calledStmt->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $p = str_replace('\\', '/', $p);
                if (!isset($seen_after[$p])) { $seen_after[$p] = true; $rel_after[] = $p; }
            }
        }

        echo json_encode(['focal' => $resolved, 'rows' => $depRows, 'rel_before' => $rel_before, 'rel_after' => $rel_after, 'db_inputs' => isset($dbIns) ? $dbIns : []]);
        break;

    case 'full_graph':
        $file = isset($_GET['file']) ? $_GET['file'] : '';
        if ($file === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing file parameter']);
            exit;
        }
        $fileFwd = str_replace('\\', '/', $file);

        $upstream   = [];
        $downstream = [];

        if ($pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptIO'")->fetchColumn()) {
            // BFS upstream: find scripts that output this file, then their inputs, recursively
            $seenUp  = [$fileFwd => true];
            $queueUp = [$fileFwd];
            while (!empty($queueUp)) {
                $cur    = array_shift($queueUp);
                $curBwd = str_replace('/', '\\', $cur);
                $scrStmt = $pdo->prepare(
                    "SELECT DISTINCT script_path FROM ScriptIO WHERE direction='output' AND (file_path=? OR file_path=?)"
                );
                $scrStmt->execute([$cur, $curBwd]);
                foreach ($scrStmt->fetchAll(PDO::FETCH_COLUMN) as $scr) {
                    $scr = str_replace('\\', '/', $scr);
                    $inStmt = $pdo->prepare(
                        "SELECT DISTINCT file_path FROM ScriptIO
                         WHERE direction='input' AND is_db_query=0
                           AND (script_path=? OR script_path=?)"
                    );
                    $inStmt->execute([$scr, str_replace('/', '\\', $scr)]);
                    foreach ($inStmt->fetchAll(PDO::FETCH_COLUMN) as $inFile) {
                        $inFile = str_replace('\\', '/', $inFile);
                        $upstream[] = ['source' => $inFile, 's0' => $scr, 'destination' => $cur];
                        if (!isset($seenUp[$inFile])) { $seenUp[$inFile] = true; $queueUp[] = $inFile; }
                    }
                }
            }

            // BFS downstream: find scripts that input this file, then their outputs, recursively
            $seenDown  = [$fileFwd => true];
            $queueDown = [$fileFwd];
            while (!empty($queueDown)) {
                $cur    = array_shift($queueDown);
                $curBwd = str_replace('/', '\\', $cur);
                $scrStmt = $pdo->prepare(
                    "SELECT DISTINCT script_path FROM ScriptIO
                     WHERE direction='input' AND is_db_query=0 AND (file_path=? OR file_path=?)"
                );
                $scrStmt->execute([$cur, $curBwd]);
                foreach ($scrStmt->fetchAll(PDO::FETCH_COLUMN) as $scr) {
                    $scr = str_replace('\\', '/', $scr);
                    $outStmt = $pdo->prepare(
                        "SELECT DISTINCT file_path FROM ScriptIO
                         WHERE direction='output' AND (script_path=? OR script_path=?)"
                    );
                    $outStmt->execute([$scr, str_replace('/', '\\', $scr)]);
                    foreach ($outStmt->fetchAll(PDO::FETCH_COLUMN) as $outFile) {
                        $outFile = str_replace('\\', '/', $outFile);
                        $downstream[] = ['source' => $cur, 's0' => $scr, 'destination' => $outFile];
                        if (!isset($seenDown[$outFile])) { $seenDown[$outFile] = true; $queueDown[] = $outFile; }
                    }
                }
            }
        }

        echo json_encode(['upstream' => $upstream, 'downstream' => $downstream]);
        break;

    case 'project_graph':
        $project = isset($_GET['project']) ? $_GET['project'] : '';

        // ── Collect project names from DatamartProjects ────────────────────
        $allProjects = [];
        $dmExists = $pdo->query(
            "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='DatamartProjects'"
        )->fetchColumn();
        if ($dmExists) {
            $allProjects = $pdo->query(
                "SELECT DISTINCT project FROM DatamartProjects WHERE project IS NOT NULL AND project != '' ORDER BY project"
            )->fetchAll(PDO::FETCH_COLUMN);
        }

        if ($project === '' || !in_array($project, $allProjects)) {
            $project = count($allProjects) > 0 ? $allProjects[0] : '';
        }

        // ── Build edge list from ScriptIO calls + ScriptMeta isolated nodes ─
        $edges       = [];
        $edgeNodes   = [];
        $groupLabels = [];

        if ($dmExists && $project !== '') {
            $ioExists = $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptIO'")->fetchColumn();
            $smExists = $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptMeta'")->fetchColumn();

            $dmStmt = $pdo->prepare(
                "SELECT id, datamart, root_dir FROM DatamartProjects WHERE project = ? ORDER BY id"
            );
            $dmStmt->execute([$project]);
            foreach ($dmStmt->fetchAll(PDO::FETCH_ASSOC) as $dm) {
                $rootFwd = rtrim(str_replace('\\', '/', $dm['root_dir']), '/');
                $grpNum  = (int)$dm['id'];
                $groupLabels[(string)$grpNum] = $dm['datamart'];

                if ($ioExists) {
                    $callStmt = $pdo->prepare(
                        "SELECT script_path AS A, file_path AS B FROM ScriptIO
                         WHERE direction='call' AND file_path LIKE '%.anatella' AND script_path LIKE ?"
                    );
                    $callStmt->execute([$rootFwd . '/%']);
                    foreach ($callStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $a = str_replace('\\', '/', $row['A']);
                        $b = str_replace('\\', '/', $row['B']);
                        $edges[]       = ['A' => $a, 'B' => $b, 'grp' => (string)$grpNum];
                        $edgeNodes[$a] = true;
                        $edgeNodes[$b] = true;
                    }
                }

                if ($smExists) {
                    $smStmt = $pdo->prepare(
                        "SELECT script_path FROM ScriptMeta WHERE script_path LIKE ? ORDER BY script_path"
                    );
                    $smStmt->execute([$rootFwd . '/%']);
                    foreach ($smStmt->fetchAll(PDO::FETCH_COLUMN) as $spath) {
                        $fwd = str_replace('\\', '/', $spath);
                        if (!isset($edgeNodes[$fwd])) {
                            $edges[]       = ['A' => $fwd, 'B' => '', 'grp' => (string)$grpNum];
                            $edgeNodes[$fwd] = true;
                        }
                    }
                }
            }
        }

        echo json_encode(['edges' => $edges, 'projects' => $allProjects, 'project' => $project, 'groupLabels' => $groupLabels]);
        break;

    case 'actions':
        $script = isset($_GET['script']) ? $_GET['script'] : '';
        if ($script === '') { echo json_encode([]); break; }
        $scriptFwd = str_replace('\\', '/', $script);
        $scriptBwd = str_replace('/', '\\', $script);
        $bn = basename($scriptFwd);

        // Prefer richer ScriptVarsExtracted table (has op, expression)
        $newExists = $pdo->query(
            "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptVarsExtracted'"
        )->fetchColumn();
        if ($newExists) {
            // Exact path match first; fall back to basename-only if no results
            $stmt = $pdo->prepare(
                'SELECT node_idx AS "ID", var_before AS "Before", var_after AS "After", op, expression
                 FROM ScriptVarsExtracted WHERE script_path = ? OR script_path = ?
                 ORDER BY CAST(node_idx AS INTEGER), var_after'
            );
            $stmt->execute([$scriptFwd, $scriptBwd]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) {
                $stmt = $pdo->prepare(
                    'SELECT node_idx AS "ID", var_before AS "Before", var_after AS "After", op, expression
                     FROM ScriptVarsExtracted WHERE script_path LIKE ? OR script_path LIKE ?
                     ORDER BY CAST(node_idx AS INTEGER), var_after'
                );
                $stmt->execute(['%/' . $bn, '%\\' . $bn]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if (!empty($rows)) { echo json_encode($rows); break; }
        }

        echo json_encode([]);
        break;

    case 'script_io':
        $script = isset($_GET['script']) ? $_GET['script'] : '';
        if ($script === '') {
            echo json_encode(['inputs' => [], 'outputs' => [], 'calls' => [], 'meta' => null]);
            break;
        }
        $scriptFwd = str_replace('\\', '/', $script);
        $scriptBwd = str_replace('/', '\\', $script);
        $bn = basename($scriptFwd);
        $result = ['inputs' => [], 'outputs' => [], 'calls' => [], 'meta' => null];

        $ioExists = $pdo->query(
            "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptIO'"
        )->fetchColumn();
        if ($ioExists) {
            // Exact path match first; fall back to basename-with-separator if no results
            $stmt = $pdo->prepare(
                "SELECT direction, file_path, action_tag, is_db_query, node_idx FROM ScriptIO
                 WHERE script_path = ? OR script_path = ?
                 ORDER BY direction, file_path"
            );
            $stmt->execute([$scriptFwd, $scriptBwd]);
            $ioRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($ioRows)) {
                $stmt = $pdo->prepare(
                    "SELECT direction, file_path, action_tag, is_db_query, node_idx FROM ScriptIO
                     WHERE script_path LIKE ? OR script_path LIKE ?
                     ORDER BY direction, file_path"
                );
                $stmt->execute(['%/' . $bn, '%\\' . $bn]);
                $ioRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($ioRows as $r) {
                if ($r['direction'] === 'input')        $result['inputs'][]  = $r;
                elseif ($r['direction'] === 'output')   $result['outputs'][] = $r;
                else                                    $result['calls'][]   = $r;
            }
        }

        $mExists = $pdo->query(
            "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptMeta'"
        )->fetchColumn();
        if ($mExists) {
            $stmt = $pdo->prepare(
                "SELECT rtfl_count, total_nodes, connected_nodes, input_count, output_count, var_count, call_count
                 FROM ScriptMeta WHERE script_path = ? OR script_path = ? LIMIT 1"
            );
            $stmt->execute([$scriptFwd, $scriptBwd]);
            $meta = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$meta) {
                $stmt = $pdo->prepare(
                    "SELECT rtfl_count, total_nodes, connected_nodes, input_count, output_count, var_count, call_count
                     FROM ScriptMeta WHERE script_path LIKE ? OR script_path LIKE ? LIMIT 1"
                );
                $stmt->execute(['%/' . $bn, '%\\' . $bn]);
                $meta = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            $result['meta'] = $meta ? $meta : null;
        }

        echo json_encode($result);
        break;

    case 'variables':
        $sveEx = $pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptVarsExtracted'")->fetchColumn();
        if ($sveEx) {
            $rows = $pdo->query(
                "SELECT DISTINCT var_after FROM ScriptVarsExtracted
                 WHERE var_after IS NOT NULL AND var_after != ''
                 ORDER BY var_after"
            )->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($rows)) { echo json_encode($rows); break; }
        }
        echo json_encode([]);
        break;

    case 'scripts_for_variable':
        $var = isset($_GET['var']) ? $_GET['var'] : '';
        if ($var === '') { echo json_encode([]); break; }
        $rows = [];
        if ($pdo->query("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptVarsExtracted'")->fetchColumn()) {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT script_path FROM ScriptVarsExtracted
                 WHERE var_after = ? OR var_before = ?
                 ORDER BY script_path"
            );
            $stmt->execute([$var, $var]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        echo json_encode($rows);
        break;

    case 'anatella_file':
        $script = isset($_GET['script']) ? $_GET['script'] : '';
        if ($script === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing script parameter']);
            exit;
        }
        $bn = basename(str_replace('\\', '/', $script));
        $fullPath = false;

        // 1. If the passed path is already absolute (e.g. E:/...), try it directly on disk
        $scriptNorm = str_replace('/', DIRECTORY_SEPARATOR, $script);
        if (preg_match('/^[A-Za-z]:/', $script) && file_exists($scriptNorm)) {
            $fullPath = $scriptNorm;
        }

        // 2. Exact match in ScriptMeta (handles full-path scripts added by the extractor)
        if (!$fullPath) {
            $smExact = $pdo->prepare("SELECT script_path FROM ScriptMeta WHERE script_path = ? LIMIT 1");
            $smExact->execute([$script]);
            $r = $smExact->fetchColumn();
            if (!$r) {
                // Also try with normalised separators
                $smExact->execute([str_replace('\\', '/', $script)]);
                $r = $smExact->fetchColumn();
            }
            if ($r) $fullPath = str_replace('/', DIRECTORY_SEPARATOR, $r);
        }

        // 3. Basename match in ScriptMeta (script passed with different path prefix)
        if (!$fullPath) {
            $smLike = $pdo->prepare("SELECT script_path FROM ScriptMeta WHERE script_path LIKE ? LIMIT 1");
            $smLike->execute(['%/' . $bn]);
            $r = $smLike->fetchColumn();
            if ($r) $fullPath = str_replace('/', DIRECTORY_SEPARATOR, $r);
        }

        if (!$fullPath) {
            echo json_encode(['error' => 'Script not found', 'basename' => $bn]);
            exit;
        }
        if (!file_exists($fullPath)) {
            echo json_encode(['error' => 'File not found on disk', 'path' => $fullPath]);
            exit;
        }
        $xml = file_get_contents($fullPath);
        echo json_encode(['xml' => $xml, 'path' => $fullPath]);
        break;

    case 'pipeline_from_db':
        $script = isset($_GET['script']) ? $_GET['script'] : '';
        if ($script === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing script parameter']);
            exit;
        }
        $scriptFwd = str_replace('\\', '/', $script);
        $scriptBwd = str_replace('/', '\\', $script);
        $bn = basename($scriptFwd);

        $mExists = $pdo->query(
            "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='ScriptMeta'"
        )->fetchColumn();
        if (!$mExists) {
            echo json_encode(['error' => 'ScriptMeta table not found — run extraction first']);
            break;
        }
        // Migration: add column if this DB pre-dates the pipeline_xml feature
        try { $pdo->exec('ALTER TABLE ScriptMeta ADD COLUMN pipeline_xml TEXT'); } catch (Exception $e) {}

        $stmt = $pdo->prepare(
            'SELECT pipeline_xml FROM ScriptMeta WHERE script_path=? OR script_path=? LIMIT 1'
        );
        $stmt->execute([$scriptFwd, $scriptBwd]);
        $xml = $stmt->fetchColumn();
        if (!$xml) {
            $stmt = $pdo->prepare(
                'SELECT pipeline_xml FROM ScriptMeta WHERE script_path LIKE ? OR script_path LIKE ? LIMIT 1'
            );
            $stmt->execute(['%/' . $bn, '%\\' . $bn]);
            $xml = $stmt->fetchColumn();
        }
        if (!$xml) {
            echo json_encode(['error' => 'No pipeline snapshot in database — re-run extraction to capture it']);
            break;
        }
        echo json_encode(['pipeline_xml' => $xml]);
        break;

    case 'open_script':
        $path = isset($_GET['path']) ? $_GET['path'] : '';
        if ($path === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Missing path parameter']);
            exit;
        }
        $ANATELLA_EXE = 'C:\\soft\\TIMi\\bin\\Anatella.exe';
        $path = str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!file_exists($path)) {
            echo json_encode(['error' => 'File not found: ' . $path]);
            exit;
        }
        if (!file_exists($ANATELLA_EXE)) {
            echo json_encode(['error' => 'Anatella.exe not found at: ' . $ANATELLA_EXE]);
            exit;
        }
        $cmd = 'START "" /B "' . $ANATELLA_EXE . '" "' . $path . '"';
        pclose(popen($cmd, 'r'));
        echo json_encode(['ok' => true, 'path' => $path]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        break;
}
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
