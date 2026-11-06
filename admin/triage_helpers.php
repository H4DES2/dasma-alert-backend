<?php
/**
 * Triage question system: shared helpers.
 *
 * Used by:
 *   - migrate_triage.php  (one-time setup, superadmin only)
 *   - admin_actions.php   (keeps emergency_incidents in sync when "Save Hazards" is pressed)
 *
 * Severity levels everywhere in these tables: 0 = none, 1 = Minor, 2 = Major, 3 = Critical.
 */

/** Lower-case that works even when the mbstring extension is not installed. */
function triageLower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Split the comma-separated hazard string into unique, trimmed names (order kept). */
function triageParseCsv(?string $csv): array {
    $out = [];
    foreach (explode(',', (string)$csv) as $name) {
        $name = trim($name);
        if ($name === '') continue;
        $key = triageLower($name);
        if (!isset($out[$key])) $out[$key] = $name;
    }
    return array_values($out);
}

/**
 * Make emergency_incidents match the comma-separated list saved from the Controls page.
 *  - names in the list but not in the table are added (base level Minor unless listed in $majorNames)
 *  - names in the table but missing from the list are removed (their question links go with them)
 *  - existing rows keep their id and base level; only sort_order is refreshed
 * A rename looks like "remove + add", so links on a renamed hazard are lost until the
 * Manage Hazards modal is rebuilt to carry ids.
 */
function syncEmergencyIncidents(mysqli $conn, int $typeId, ?string $csv, array $majorNames = []): array {
    $names = triageParseCsv($csv);
    $stats = ['added' => 0, 'removed' => 0];
    $majorKeys = array_map('triageLower', $majorNames);

    $conn->begin_transaction();
    try {
        $ins = $conn->prepare(
            "INSERT INTO emergency_incidents (type_id, name, base_level, sort_order)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), name = VALUES(name)"
        );
        foreach ($names as $i => $name) {
            $level = in_array(triageLower($name), $majorKeys, true) ? 2 : 1;
            $ins->bind_param('isii', $typeId, $name, $level, $i);
            $ins->execute();
            if ($ins->affected_rows === 1) $stats['added']++;
        }
        $ins->close();

        $keep = [];
        foreach ($names as $n) $keep[triageLower($n)] = true;

        $sel = $conn->prepare("SELECT id, name FROM emergency_incidents WHERE type_id = ?");
        $sel->bind_param('i', $typeId);
        $sel->execute();
        $rows = $sel->get_result()->fetch_all(MYSQLI_ASSOC);
        $sel->close();

        $del = $conn->prepare("DELETE FROM emergency_incidents WHERE id = ?");
        foreach ($rows as $r) {
            if (!isset($keep[triageLower($r['name'])])) {
                $id = (int)$r['id'];
                $del->bind_param('i', $id);
                $del->execute();
                $stats['removed']++;
            }
        }
        $del->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return $stats;
}

/** Create the triage tables (and incidents.triage_answers) if they do not exist yet. */
function triageEnsureSchema(mysqli $conn): array {
    $log = [];
    $tail = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $conn->query("CREATE TABLE IF NOT EXISTS emergency_incidents (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        type_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        base_level TINYINT NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        UNIQUE KEY uq_type_name (type_id, name),
        CONSTRAINT fk_ei_type FOREIGN KEY (type_id) REFERENCES emergency_types(id) ON DELETE CASCADE
    ) $tail");

    $conn->query("CREATE TABLE IF NOT EXISTS triage_questions (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        question_text VARCHAR(255) NOT NULL,
        unsure_level TINYINT NOT NULL DEFAULT 0,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        sort_order INT NOT NULL DEFAULT 0
    ) $tail");

    $conn->query("CREATE TABLE IF NOT EXISTS triage_options (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        question_id INT NOT NULL,
        label VARCHAR(150) NOT NULL,
        level TINYINT NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        KEY idx_opt_question (question_id),
        CONSTRAINT fk_to_question FOREIGN KEY (question_id) REFERENCES triage_questions(id) ON DELETE CASCADE
    ) $tail");

    $conn->query("CREATE TABLE IF NOT EXISTS triage_question_links (
        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        question_id INT NOT NULL,
        type_id INT NULL,
        incident_id INT NULL,
        KEY idx_link_question (question_id),
        KEY idx_link_type (type_id),
        KEY idx_link_incident (incident_id),
        CONSTRAINT fk_tl_question FOREIGN KEY (question_id) REFERENCES triage_questions(id) ON DELETE CASCADE,
        CONSTRAINT fk_tl_type FOREIGN KEY (type_id) REFERENCES emergency_types(id) ON DELETE CASCADE,
        CONSTRAINT fk_tl_incident FOREIGN KEY (incident_id) REFERENCES emergency_incidents(id) ON DELETE CASCADE
    ) $tail");
    $log[] = 'Tables ready: emergency_incidents, triage_questions, triage_options, triage_question_links';

    $col = $conn->query("SHOW COLUMNS FROM incidents LIKE 'triage_answers'");
    if ($col && $col->num_rows === 0) {
        $conn->query("ALTER TABLE incidents ADD COLUMN triage_answers TEXT NULL");
        $log[] = 'Added incidents.triage_answers';
    } else {
        $log[] = 'incidents.triage_answers already present';
    }
    return $log;
}

/** Copy the comma-separated hazards of every emergency type into emergency_incidents. */
function triageImportIncidents(mysqli $conn, array $baseMajor): array {
    $log = [];
    $types = $conn->query("SELECT id, name, incidents FROM emergency_types ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    foreach ($types as $t) {
        $major = $baseMajor[$t['name']] ?? [];
        $s = syncEmergencyIncidents($conn, (int)$t['id'], $t['incidents'], $major);
        $log[] = sprintf('%-14s +%d added, -%d removed', $t['name'], $s['added'], $s['removed']);
    }
    return $log;
}

/** Load the question library from triage_seed_data.php (only when the library is empty). */
function triageSeedQuestions(mysqli $conn, array $seed): array {
    $log = [];
    $count = (int)$conn->query("SELECT COUNT(*) c FROM triage_questions")->fetch_assoc()['c'];
    if ($count > 0) {
        return ["Seed skipped: library already has $count questions (nothing overwritten)"];
    }

    $typeIds = [];
    foreach ($conn->query("SELECT id, name FROM emergency_types")->fetch_all(MYSQLI_ASSOC) as $r) {
        $typeIds[triageLower($r['name'])] = (int)$r['id'];
    }
    $incIds = [];
    foreach ($conn->query("SELECT id, type_id, name FROM emergency_incidents")->fetch_all(MYSQLI_ASSOC) as $r) {
        $incIds[$r['type_id'] . '|' . triageLower($r['name'])] = (int)$r['id'];
    }

    $conn->begin_transaction();
    try {
        $qIns = $conn->prepare("INSERT INTO triage_questions (question_text, unsure_level, sort_order) VALUES (?, ?, ?)");
        $oIns = $conn->prepare("INSERT INTO triage_options (question_id, label, level, sort_order) VALUES (?, ?, ?, ?)");
        $lIns = $conn->prepare("INSERT INTO triage_question_links (question_id, type_id, incident_id) VALUES (?, ?, ?)");

        $nQ = $nO = $nL = 0;
        foreach ($seed['questions'] as $i => $q) {
            $qIns->bind_param('sii', $q['text'], $q['unsure'], $i);
            $qIns->execute();
            $qid = (int)$conn->insert_id;
            $nQ++;

            foreach ($q['opts'] as $k => $raw) {
                $p = strrpos($raw, '=');
                $label = trim(substr($raw, 0, $p));
                $level = (int)substr($raw, $p + 1);
                $oIns->bind_param('isii', $qid, $label, $level, $k);
                $oIns->execute();
                $nO++;
            }

            foreach ($q['links'] as $link) {
                $parts = explode(':', $link, 2);
                $typeKey = triageLower(trim($parts[0]));
                if (!isset($typeIds[$typeKey])) {
                    $log[] = "WARN: type '{$parts[0]}' not found, link skipped (\"{$q['text']}\")";
                    continue;
                }
                $typeId = $typeIds[$typeKey];
                if (count($parts) === 1) {
                    $incId = null;
                    $lIns->bind_param('iii', $qid, $typeId, $incId);
                } else {
                    $key = $typeId . '|' . triageLower(trim($parts[1]));
                    if (!isset($incIds[$key])) {
                        $log[] = "WARN: incident '{$parts[1]}' not found under '{$parts[0]}', link skipped (\"{$q['text']}\")";
                        continue;
                    }
                    $incId = $incIds[$key];
                    $nullType = null;
                    $lIns->bind_param('iii', $qid, $nullType, $incId);
                }
                $lIns->execute();
                $nL++;
            }
        }
        $conn->commit();
        $log[] = "Seeded $nQ questions, $nO options, $nL links";
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    return $log;
}

/** How many questions each incident would show, flagging gaps and overly long sheets. */
function triageCoverageReport(mysqli $conn): array {
    $rows = $conn->query(
        "SELECT t.name AS type_name, ei.name,
                (SELECT COUNT(DISTINCT l.question_id)
                   FROM triage_question_links l
                   JOIN triage_questions q ON q.id = l.question_id AND q.status = 'active'
                  WHERE l.incident_id = ei.id
                     OR (l.incident_id IS NULL AND l.type_id = ei.type_id)) AS qcount
           FROM emergency_incidents ei
           JOIN emergency_types t ON t.id = ei.type_id
          ORDER BY t.name, ei.sort_order"
    )->fetch_all(MYSQLI_ASSOC);

    $log = [];
    $none = $long = 0;
    foreach ($rows as $r) {
        $n = (int)$r['qcount'];
        if ($n === 0) { $none++; $log[] = "NO QUESTIONS: {$r['type_name']} - {$r['name']}"; }
        if ($n > 4)   { $long++; $log[] = "LONG ({$n}): {$r['type_name']} - {$r['name']}"; }
    }
    $log[] = sprintf('Coverage: %d incidents, %d without questions, %d with more than 4', count($rows), $none, $long);
    return $log;
}

/**
 * Work out the severity of a report on the server (never trust the app's own number).
 *
 *   level = max( incident base level,
 *                level of each chosen option,
 *                "unsure" level of each linked question that was left unanswered )
 *
 * Options that do not belong to a question linked to this incident are ignored.
 * Returns null when the incident id is unknown.
 */
function triageComputeSeverity(mysqli $conn, int $incidentId, array $optionIds): ?array {
    $st = $conn->prepare("SELECT type_id, base_level FROM emergency_incidents WHERE id = ?");
    $st->bind_param('i', $incidentId);
    $st->execute();
    $inc = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$inc) return null;

    $typeId = (int)$inc['type_id'];
    $st = $conn->prepare(
        "SELECT DISTINCT q.id, q.unsure_level
           FROM triage_questions q
           JOIN triage_question_links l ON l.question_id = q.id
          WHERE q.status = 'active'
            AND (l.incident_id = ? OR (l.incident_id IS NULL AND l.type_id = ?))"
    );
    $st->bind_param('ii', $incidentId, $typeId);
    $st->execute();
    $linked = [];
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $linked[(int)$r['id']] = (int)$r['unsure_level'];
    }
    $st->close();

    $answered = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $optionIds), fn($v) => $v > 0)));
    if ($ids && $linked) {
        $in = implode(',', $ids);
        $res = $conn->query("SELECT o.question_id, o.level, o.label, q.question_text
                               FROM triage_options o JOIN triage_questions q ON q.id = o.question_id
                              WHERE o.id IN ($in)");
        foreach ($res->fetch_all(MYSQLI_ASSOC) as $r) {
            $qid = (int)$r['question_id'];
            if (!isset($linked[$qid])) continue;
            $lvl = (int)$r['level'];
            if (!isset($answered[$qid]) || $lvl > $answered[$qid]['level']) {
                $answered[$qid] = ['question' => $r['question_text'], 'answer' => $r['label'], 'level' => $lvl];
            }
        }
    }

    $level = max(1, (int)$inc['base_level']);
    $unanswered = 0;
    foreach ($linked as $qid => $unsure) {
        if (isset($answered[$qid])) {
            $level = max($level, $answered[$qid]['level']);
        } else {
            $level = max($level, $unsure);
            $unanswered++;
        }
    }
    $level = min(3, $level);

    return [
        'level'      => $level,
        'severity'   => [1 => 'Minor', 2 => 'Major', 3 => 'Critical'][$level],
        'answers'    => array_values($answered),   // snapshot for incidents.triage_answers
        'unanswered' => $unanswered,
    ];
}

/** Run every migration step. Safe to run more than once. */
function runTriageMigration(mysqli $conn, array $seed): array {
    $log = ['== Schema =='];
    $log = array_merge($log, triageEnsureSchema($conn));
    $log[] = '';
    $log[] = '== Importing hazards into emergency_incidents ==';
    $log = array_merge($log, triageImportIncidents($conn, $seed['base_major']));
    $log[] = '';
    $log[] = '== Question library ==';
    $log = array_merge($log, triageSeedQuestions($conn, $seed));
    $log[] = '';
    $log[] = '== Coverage ==';
    $log = array_merge($log, triageCoverageReport($conn));
    return $log;
}
