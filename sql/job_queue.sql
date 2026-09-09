-- Generic background job queue.
--
-- A web request enqueues a row here (status = 'pending') with a `type` and a JSON
-- `payload`; the background worker (`php spark queue:work`, scheduled every five minutes by
-- Task Scheduler via scripts/queue-worker.ps1) claims it and dispatches to the
-- handler registered for that type in Config\Queue. App\Models\Jobs\JobQueueModel::
-- ensureTable() creates this table on demand, so importing this file is optional —
-- it is the canonical DDL for the accesscard schema dump.

CREATE TABLE IF NOT EXISTS `job_queue` (
    `jobID`          INT NOT NULL AUTO_INCREMENT,
    `type`           VARCHAR(64) NOT NULL,
    `payload`        LONGTEXT NULL,
    `status`         ENUM('pending','processing','done','partial','failed') NOT NULL DEFAULT 'pending',
    `progress_total` INT NOT NULL DEFAULT 0,
    `progress_done`  INT NOT NULL DEFAULT 0,
    `checkpoint`     INT NOT NULL DEFAULT 0,
    `result_json`    LONGTEXT NULL,
    `message`        VARCHAR(500) NULL,
    `userID`         INT NULL,
    `ip_address`     VARCHAR(45) NULL,
    `user_agent`     VARCHAR(255) NULL,
    `attempts`       INT NOT NULL DEFAULT 0,
    `max_attempts`   INT NOT NULL DEFAULT 1,
    `available_at`   DATETIME NULL,
    `locked_at`      DATETIME NULL,
    `locked_by`      VARCHAR(64) NULL,
    `dt_created`     DATETIME NOT NULL,
    `dt_started`     DATETIME NULL,
    `dt_finished`    DATETIME NULL,
    PRIMARY KEY (`jobID`),
    KEY `idx_claim` (`status`, `available_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
