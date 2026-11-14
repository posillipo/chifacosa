-- Marketing > Pianificate, parte 2: ESECUZIONI e CRONOLOGIA.
-- Il progetto diventa un modello riutilizzabile (passi + istruzioni); ogni volta che viene usato nasce
-- un'esecuzione con la sua cronologia (chi, quando, cosa è stato fatto). Interruttore Attivo sul progetto.
-- Da eseguire UNA volta su ogni database (ripeterla non fa danni). Non cancella nulla: i passi già
-- completati e i loro esiti diventano la prima esecuzione del progetto.

-- 1) Interruttore Attivo (default: attivo)
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'marketing_projects' AND COLUMN_NAME = 'is_active');
SET @s := IF(@c = 0, 'ALTER TABLE marketing_projects ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER status', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2) Esecuzioni e cronologia
CREATE TABLE IF NOT EXISTS marketing_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    started_by ENUM('user','ai') NOT NULL DEFAULT 'user',
    status ENUM('running','completed','interrupted') NOT NULL DEFAULT 'running',
    steps_total INT NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME DEFAULT NULL,
    KEY idx_mkt_runs_project (project_id, id),
    KEY idx_mkt_runs_user (user_id, status),
    FOREIGN KEY (project_id) REFERENCES marketing_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketing_run_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    run_id INT NOT NULL,
    project_id INT NOT NULL,
    user_id INT NOT NULL,
    step_id INT DEFAULT NULL,
    step_label VARCHAR(200) DEFAULT NULL,
    kind ENUM('started','note','step_done','finished','interrupted') NOT NULL,
    actor ENUM('user','ai') NOT NULL DEFAULT 'user',
    body TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mkt_events_run (run_id, id),
    KEY idx_mkt_events_step (run_id, step_id, kind),
    FOREIGN KEY (run_id) REFERENCES marketing_runs(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3) I passi già completati (e i loro esiti) diventano la prima esecuzione del progetto
INSERT INTO marketing_runs (project_id, user_id, started_by, status, steps_total, started_at, finished_at)
SELECT p.id, p.user_id,
       COALESCE((SELECT s2.completed_by FROM marketing_steps s2 WHERE s2.project_id = p.id AND s2.status = 'done' ORDER BY s2.position LIMIT 1), 'user'),
       IF(p.status = 'completed', 'completed', 'running'),
       (SELECT COUNT(*) FROM marketing_steps s3 WHERE s3.project_id = p.id),
       COALESCE(MIN(s.completed_at), p.created_at),
       IF(p.status = 'completed', COALESCE(p.completed_at, MAX(s.completed_at)), NULL)
FROM marketing_projects p JOIN marketing_steps s ON s.project_id = p.id AND s.status = 'done'
WHERE NOT EXISTS (SELECT 1 FROM marketing_runs r WHERE r.project_id = p.id)
GROUP BY p.id, p.user_id, p.status, p.completed_at, p.created_at;

INSERT INTO marketing_run_events (run_id, project_id, user_id, step_id, step_label, kind, actor, body, created_at)
SELECT r.id, s.project_id, s.user_id, s.id,
       CONCAT(IF(s.is_final = 1, 'Finale', CONCAT('Passo ', (SELECT COUNT(*) FROM marketing_steps s4 WHERE s4.project_id = s.project_id AND s4.is_final = 0 AND s4.position <= s.position))), ' — ', LEFT(s.title, 150)),
       'step_done', COALESCE(s.completed_by, 'user'), s.result, COALESCE(s.completed_at, r.started_at)
FROM marketing_steps s JOIN marketing_runs r ON r.project_id = s.project_id
WHERE s.status = 'done'
  AND NOT EXISTS (SELECT 1 FROM marketing_run_events e2 WHERE e2.run_id = r.id);

-- 4) "Completato" non è più uno stato del progetto (lo è dell'esecuzione): i progetti completati tornano attivi
UPDATE marketing_projects SET status = 'active', completed_at = NULL WHERE status = 'completed';
