<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('VM4_CLEANUP_WEB_CONTEXT')) {
    http_response_code(404);
    exit("Not found\n");
}

const VERSION = '1.0.0';

final class Cleanup
{
    private PDO $db;
    private PDO $audit;
    private string $prefix;
    private array $columns = [];
    private array $fullColumns = [];
    private ?array $tableList = null;
    private array $mediaRoots;
    private array $options;

    public function __construct(array $config, array $options)
    {
        $this->options = $options;
        $this->prefix = (string)($config['table_prefix'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $this->prefix)) {
            throw new RuntimeException('Set table_prefix in the config to the actual Joomla table prefix.');
        }

        $dsn = (string)($config['dsn'] ?? '');
        $user = (string)($config['username'] ?? '');
        $password = (string)($config['password'] ?? '');
        if ($dsn === '') {
            throw new RuntimeException('Set a PDO MySQL DSN in the config.');
        }
        $this->db = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $auditPath = $options['audit-db'] ?? $config['audit_db'] ?? (__DIR__ . DIRECTORY_SEPARATOR . 'vm4-cleanup.sqlite');
        $auditDir = dirname($auditPath);
        if (!is_dir($auditDir) || !is_writable($auditDir)) {
            throw new RuntimeException("SQLite audit directory is missing or not writable: {$auditDir}");
        }
        $this->audit = new PDO('sqlite:' . $auditPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->audit->exec('PRAGMA journal_mode=WAL');
        $this->audit->exec(
            'CREATE TABLE IF NOT EXISTS cleanup_runs (
                run_id INTEGER PRIMARY KEY AUTOINCREMENT,
                started_at TEXT NOT NULL,
                finished_at TEXT,
                mode TEXT NOT NULL,
                cutoff_date TEXT,
                dry_run INTEGER NOT NULL,
                site_label TEXT NOT NULL,
                summary TEXT
            )'
        );
        $this->audit->exec(
            'CREATE TABLE IF NOT EXISTS cleanup_events (
                event_id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                entity_table TEXT NOT NULL,
                entity_id TEXT NOT NULL,
                status TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                recorded_at TEXT NOT NULL,
                FOREIGN KEY (run_id) REFERENCES cleanup_runs(run_id)
            )'
        );
        $this->audit->exec('CREATE INDEX IF NOT EXISTS cleanup_events_run_idx ON cleanup_events(run_id)');

        $roots = $options['media-root'] !== null ? [$options['media-root']] : ($config['media_roots'] ?? []);
        if (!is_array($roots) || count($roots) === 0) {
            throw new RuntimeException('Configure at least one absolute media_roots directory or pass --media-root.');
        }
        $this->mediaRoots = [];
        foreach ($roots as $root) {
            $real = realpath((string)$root);
            if ($real === false || !is_dir($real)) {
                throw new RuntimeException("Media root does not exist: {$root}");
            }
            $this->mediaRoots[] = rtrim($real, DIRECTORY_SEPARATOR);
        }
    }

    public function run(): void
    {
        $mode = $this->options['mode'];
        $cutoff = $this->options['cutoff'];
        if (in_array($mode, ['old-products', 'all'], true) && $cutoff === null) {
            throw new RuntimeException('--cutoff=YYYY-MM-DD is required for old-products and all modes.');
        }

        $this->assertSchema();
        $runId = $this->startRun($mode, $cutoff);
        $stats = ['products_deleted' => 0, 'products_protected' => 0, 'images_deleted' => 0, 'media_rows_deleted' => 0, 'errors' => 0];

        try {
            if (in_array($mode, ['old-products', 'all'], true)) {
                $productStats = $this->deleteOldProducts($runId, $cutoff);
                $stats['products_deleted'] = $productStats['deleted'];
                $stats['products_protected'] = $productStats['protected'];
                if ($mode === 'old-products') {
                    $imageStats = $this->cleanOrphanImages(
                        $runId,
                        $productStats['media_ids'],
                        $productStats['product_ids']
                    );
                    $stats['images_deleted'] = $imageStats['files'];
                    $stats['media_rows_deleted'] = $imageStats['rows'];
                    $stats['errors'] += $imageStats['errors'];
                }
            }
            if (in_array($mode, ['orphan-images', 'all'], true)) {
                $imageStats = $this->cleanOrphanImages($runId);
                $stats['images_deleted'] = $imageStats['files'];
                $stats['media_rows_deleted'] = $imageStats['rows'];
                $stats['errors'] += $imageStats['errors'];
                if ($mode === 'all' && !$this->options['execute'] && $productStats['media_ids'] !== []) {
                    $plannedProductImages = $this->cleanOrphanImages(
                        $runId,
                        $productStats['media_ids'],
                        $productStats['product_ids']
                    );
                    $stats['images_deleted'] += $plannedProductImages['files'];
                    $stats['media_rows_deleted'] += $plannedProductImages['rows'];
                    $stats['errors'] += $plannedProductImages['errors'];
                }
            }
            $this->finishRun($runId, $stats);
            $this->printSummary($runId, $stats);
        } catch (Throwable $e) {
            $this->finishRun($runId, $stats + ['failure' => $e->getMessage()]);
            throw $e;
        }
    }

    private function assertSchema(): void
    {
        foreach ([
            'virtuemart_products' => ['virtuemart_product_id', 'created_on', 'product_parent_id'],
            'virtuemart_order_items' => ['virtuemart_product_id'],
            'virtuemart_product_medias' => ['virtuemart_product_id', 'virtuemart_media_id'],
            'virtuemart_medias' => ['virtuemart_media_id', 'file_is_product_image', 'file_url'],
        ] as $table => $needed) {
            $found = $this->getColumns($table);
            foreach ($needed as $column) {
                if (!in_array($column, $found, true)) {
                    throw new RuntimeException("Expected VirtueMart 4 column {$this->table($table)}.{$column} was not found.");
                }
            }
        }
    }

    private function deleteOldProducts(int $runId, string $cutoff): array
    {
        $products = $this->table('virtuemart_products');
        $orders = $this->table('virtuemart_order_items');
        $stmt = $this->db->prepare(
            "SELECT p.* FROM `{$products}` p
             WHERE p.`created_on` < :cutoff
             ORDER BY p.`virtuemart_product_id`"
        );
        $stmt->execute(['cutoff' => $cutoff . ' 00:00:00']);
        $rows = $stmt->fetchAll();
        $limit = $this->options['limit'];
        if ($limit !== null) {
            $rows = array_slice($rows, 0, $limit);
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['virtuemart_product_id']] = $row;
        }
        $candidates = array_fill_keys(array_keys($byId), true);
        if ($candidates === []) {
            echo "No products were created before {$cutoff}.\n";
            return ['deleted' => 0, 'protected' => 0, 'media_ids' => [], 'product_ids' => []];
        }

        $ids = array_keys($candidates);
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $ordered = $this->db->prepare(
                "SELECT DISTINCT `virtuemart_product_id` FROM `{$orders}` WHERE `virtuemart_product_id` IN ({$in})"
            );
            $ordered->execute($chunk);
            foreach ($ordered->fetchAll(PDO::FETCH_COLUMN) as $id) {
                unset($candidates[(int)$id]);
            }
        }

        $protectedByOrder = count($byId) - count($candidates);
        if (in_array('product_parent_id', $this->getColumns('virtuemart_products'), true)) {
            do {
                $before = count($candidates);
                $this->protectParentsWithRetainedChildren($candidates);
            } while (count($candidates) < $before);
        }

        $protected = count($byId) - count($candidates);
        echo count($byId) . " qualifying product(s); {$protected} will be preserved (order reference or retained parent/child relationship).\n";
        $deleteIds = array_keys($candidates);
        if ($deleteIds === []) {
            return ['deleted' => 0, 'protected' => $protected, 'media_ids' => [], 'product_ids' => []];
        }

        $relationTables = [
            'virtuemart_product_prices',
            'virtuemart_product_manufacturers',
            'virtuemart_product_categories',
            'virtuemart_product_customfields',
            'virtuemart_product_medias',
            'virtuemart_product_shoppergroups',
        ];
        $deleted = 0;
        $mediaIds = [];
        foreach (array_chunk($deleteIds, max(1, $this->options['batch-size'])) as $batch) {
            $this->db->beginTransaction();
            try {
                foreach ($batch as $id) {
                    $snapshot = ['product' => $byId[$id], 'relations' => []];
                    foreach ($relationTables as $relation) {
                        if (!$this->tableExists($relation)) {
                            continue;
                        }
                        $cols = $this->getColumns($relation);
                        if (!in_array('virtuemart_product_id', $cols, true)) {
                            continue;
                        }
                        $table = $this->table($relation);
                        $related = $this->db->prepare("SELECT * FROM `{$table}` WHERE `virtuemart_product_id` = ?");
                        $related->execute([$id]);
                        $snapshot['relations'][$relation] = $related->fetchAll();
                    }
                    foreach ($snapshot['relations']['virtuemart_product_medias'] ?? [] as $mediaLink) {
                        $mediaIds[] = (int)$mediaLink['virtuemart_media_id'];
                    }
                    $this->recordEvent($runId, 'product', 'virtuemart_products', (string)$id, $this->options['execute'] ? 'pending' : 'dry-run', $snapshot);
                }
                if ($this->options['execute']) {
                    $in = implode(',', array_fill(0, count($batch), '?'));
                    foreach ($relationTables as $relation) {
                        if (!$this->tableExists($relation)) {
                            continue;
                        }
                        if (!in_array('virtuemart_product_id', $this->getColumns($relation), true)) {
                            continue;
                        }
                        $table = $this->table($relation);
                        $delete = $this->db->prepare("DELETE FROM `{$table}` WHERE `virtuemart_product_id` IN ({$in})");
                        $delete->execute($batch);
                    }
                    $productTable = $this->table('virtuemart_products');
                    $delete = $this->db->prepare("DELETE FROM `{$productTable}` WHERE `virtuemart_product_id` IN ({$in})");
                    $delete->execute($batch);
                    $this->db->commit();
                    $this->setEventStatus($runId, 'virtuemart_products', $batch, 'deleted');
                    $deleted += count($batch);
                } else {
                    $this->db->rollBack();
                    $deleted += count($batch);
                }
            } catch (Throwable $e) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }
        return [
            'deleted' => $deleted,
            'protected' => $protected,
            'media_ids' => array_values(array_unique($mediaIds)),
            'product_ids' => $deleteIds,
        ];
    }

    private function protectParentsWithRetainedChildren(array &$candidates): void
    {
        if ($candidates === []) {
            return;
        }
        $products = $this->table('virtuemart_products');
        foreach (array_chunk(array_keys($candidates), 500) as $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->db->prepare(
                "SELECT `virtuemart_product_id`, `product_parent_id` FROM `{$products}` WHERE `product_parent_id` IN ({$in})"
            );
            $stmt->execute($ids);
            while ($child = $stmt->fetch()) {
                $parentId = (int)$child['product_parent_id'];
                if (!isset($candidates[(int)$child['virtuemart_product_id']])) {
                    unset($candidates[$parentId]);
                }
            }
        }
    }

    private function cleanOrphanImages(int $runId, ?array $onlyMediaIds = null, array $ignoreProductIds = []): array
    {
        $mediaTable = $this->table('virtuemart_medias');
        $linksTable = $this->table('virtuemart_product_medias');
        if ($onlyMediaIds !== null) {
            if ($onlyMediaIds === []) {
                return ['files' => 0, 'rows' => 0, 'errors' => 0];
            }
            $mediaRows = [];
            foreach (array_chunk($onlyMediaIds, 500) as $chunk) {
                $in = implode(',', array_fill(0, count($chunk), '?'));
                $query = $this->db->prepare(
                    "SELECT m.* FROM `{$mediaTable}` m
                     WHERE m.`file_is_product_image` = 1 AND m.`virtuemart_media_id` IN ({$in})"
                );
                $query->execute($chunk);
                $mediaRows = array_merge($mediaRows, $query->fetchAll());
            }
        } else {
            $query = $this->db->query(
                "SELECT m.* FROM `{$mediaTable}` m
                 LEFT JOIN `{$linksTable}` pm ON pm.`virtuemart_media_id` = m.`virtuemart_media_id`
                 WHERE pm.`virtuemart_media_id` IS NULL AND m.`file_is_product_image` = 1"
            );
            $mediaRows = $query->fetchAll();
        }
        if ($onlyMediaIds !== null) {
            $allowed = array_fill_keys(array_map('intval', $onlyMediaIds), true);
            $mediaRows = array_values(array_filter(
                $mediaRows,
                static fn(array $row): bool => isset($allowed[(int)$row['virtuemart_media_id']])
            ));
        }
        $mediaIds = array_map(static fn(array $row): int => (int)$row['virtuemart_media_id'], $mediaRows);
        $referencedIds = $this->getReferencedMediaIds($mediaIds, $ignoreProductIds);
        $events = [];
        foreach ($mediaRows as $media) {
            $id = (string)$media['virtuemart_media_id'];
            if (isset($referencedIds[(int)$id])) {
                continue;
            }
            $paths = [];
            $unresolved = [];
            foreach (['file_url', 'file_url_thumb'] as $column) {
                $value = (string)($media[$column] ?? '');
                if ($value === '') {
                    continue;
                }
                $resolved = $this->resolveMediaPath($value);
                if ($resolved !== null && is_file($resolved)) {
                    $paths[$resolved] = true;
                } elseif (preg_match('/^[a-z][a-z0-9+.-]*:/i', $value) || str_starts_with($value, '//') || $this->resolveMediaPath($value) === null) {
                    $unresolved[] = $value;
                }
            }
            $payload = ['media' => $media, 'files' => array_keys($paths), 'unresolved_files' => $unresolved];
            $this->recordEvent($runId, 'orphan-image', 'virtuemart_medias', $id, $this->options['execute'] ? 'pending' : 'dry-run', $payload);
            $events[] = ['id' => $id, 'payload' => $payload];
        }
        echo count($events) . " unreferenced product-image media record(s) identified.\n";

        $fileCount = 0;
        $rowCount = 0;
        $errors = 0;
        if (!$this->options['execute']) {
            $plannedFiles = [];
            foreach ($events as $event) {
                $plannedFiles = array_merge($plannedFiles, $event['payload']['files']);
            }
            return ['files' => count(array_unique($plannedFiles)), 'rows' => count($events), 'errors' => 0];
        }

        foreach ($events as $event) {
            $safeToRemoveRow = $event['payload']['unresolved_files'] === [];
            if (!$safeToRemoveRow) {
                $errors++;
                error_log("Skipping media row {$event['id']}: one or more image URLs are outside configured local media roots.");
            }
            foreach ($event['payload']['files'] as $path) {
                if (!$this->isFileReferencedByOtherMedia($path, (int)$event['id'])) {
                    if (!unlink($path)) {
                        $safeToRemoveRow = false;
                        $errors++;
                        error_log("Could not remove image file: {$path}");
                    } else {
                        $fileCount++;
                    }
                }
            }
            if ($safeToRemoveRow) {
                $this->db->beginTransaction();
                try {
                    if ($this->isMediaReferencedElsewhere((int)$event['id'])) {
                        $this->db->commit();
                        continue;
                    }
                    $delete = $this->db->prepare("DELETE FROM `{$mediaTable}` WHERE `virtuemart_media_id` = ?");
                    $delete->execute([(int)$event['id']]);
                    $this->db->commit();
                    $rowCount += $delete->rowCount();
                    $this->setOneEventStatus($runId, 'virtuemart_medias', $event['id'], 'deleted');
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    throw $e;
                }
            }
        }
        return ['files' => $fileCount, 'rows' => $rowCount, 'errors' => $errors];
    }

    private function getReferencedMediaIds(array $mediaIds, array $ignoreProductIds = []): array
    {
        if ($mediaIds === []) {
            return [];
        }
        $mediaTable = $this->table('virtuemart_medias');
        $referenced = [];
        $ignoredProducts = array_fill_keys(array_map('intval', $ignoreProductIds), true);
        foreach ($this->getTables() as $tableName) {
            if ($tableName === $mediaTable) {
                continue;
            }
            foreach ($this->getColumnsByFullName($tableName) as $column) {
                if (!in_array($column, ['virtuemart_media_id', 'media_id'], true)) {
                    continue;
                }
                foreach (array_chunk($mediaIds, 500) as $chunk) {
                    $in = implode(',', array_fill(0, count($chunk), '?'));
                    $params = $chunk;
                    $where = "`{$column}` IN ({$in})";
                    if ($tableName === $this->table('virtuemart_product_medias') && $ignoreProductIds !== []) {
                        $sql = "SELECT DISTINCT `{$column}`, `virtuemart_product_id` FROM `{$tableName}` WHERE {$where}";
                        $stmt = $this->db->prepare($sql);
                        $stmt->execute($params);
                        foreach ($stmt->fetchAll() as $row) {
                            if (!isset($ignoredProducts[(int)$row['virtuemart_product_id']])) {
                                $referenced[(int)$row[$column]] = true;
                            }
                        }
                        continue;
                    }
                    $sql = "SELECT DISTINCT `{$column}` FROM `{$tableName}` WHERE {$where}";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($params);
                    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                        $referenced[(int)$id] = true;
                    }
                }
            }
        }
        return $referenced;
    }

    private function isMediaReferencedElsewhere(int $mediaId): bool
    {
        return isset($this->getReferencedMediaIds([$mediaId])[$mediaId]);
    }

    private function isFileReferencedByOtherMedia(string $path, int $excludeId): bool
    {
        $table = $this->table('virtuemart_medias');
        $stmt = $this->db->prepare(
            "SELECT `virtuemart_media_id`, `file_url`, `file_url_thumb` FROM `{$table}`
             WHERE `virtuemart_media_id` <> ?"
        );
        $stmt->execute([$excludeId]);
        while ($row = $stmt->fetch()) {
            foreach (['file_url', 'file_url_thumb'] as $column) {
                if (($row[$column] ?? '') !== '' && $this->resolveMediaPath((string)$row[$column]) === $path) {
                    return true;
                }
            }
        }
        return false;
    }

    private function resolveMediaPath(string $url): ?string
    {
        $url = rawurldecode(str_replace('\\', '/', trim($url)));
        if ($url === '' || preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) || str_starts_with($url, '//')) {
            return null;
        }
        $url = ltrim($url, '/');
        $segments = explode('/', $url);
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }
        foreach ($this->mediaRoots as $root) {
            $candidate = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
            $real = realpath($candidate);
            if ($real === false || !is_file($real)) {
                continue;
            }
            if ($real === $root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            return $real;
        }
        return null;
    }

    private function startRun(string $mode, ?string $cutoff): int
    {
        $stmt = $this->audit->prepare(
            'INSERT INTO cleanup_runs (started_at, mode, cutoff_date, dry_run, site_label)
             VALUES (:started, :mode, :cutoff, :dry, :site)'
        );
        $stmt->execute([
            'started' => gmdate('c'),
            'mode' => $mode,
            'cutoff' => $cutoff,
            'dry' => $this->options['execute'] ? 0 : 1,
            'site' => (string)($this->options['site-label'] ?? 'unspecified-site'),
        ]);
        return (int)$this->audit->lastInsertId();
    }

    private function finishRun(int $runId, array $stats): void
    {
        $stmt = $this->audit->prepare('UPDATE cleanup_runs SET finished_at = ?, summary = ? WHERE run_id = ?');
        $stmt->execute([gmdate('c'), json_encode($stats, JSON_THROW_ON_ERROR), $runId]);
    }

    private function recordEvent(int $runId, string $type, string $table, string $id, string $status, array $payload): void
    {
        $stmt = $this->audit->prepare(
            'INSERT INTO cleanup_events (run_id, event_type, entity_table, entity_id, status, payload_json, recorded_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$runId, $type, $table, $id, $status, json_encode($payload, JSON_THROW_ON_ERROR), gmdate('c')]);
    }

    private function setEventStatus(int $runId, string $table, array $ids, string $status): void
    {
        foreach ($ids as $id) {
            $this->setOneEventStatus($runId, $table, (string)$id, $status);
        }
    }

    private function setOneEventStatus(int $runId, string $table, string $id, string $status): void
    {
        $stmt = $this->audit->prepare(
            'UPDATE cleanup_events SET status = ? WHERE run_id = ? AND entity_table = ? AND entity_id = ?'
        );
        $stmt->execute([$status, $runId, $table, $id]);
    }

    private function printSummary(int $runId, array $stats): void
    {
        echo ($this->options['execute'] ? 'EXECUTED' : 'DRY RUN') . " — audit run #{$runId}\n";
        echo "Products " . ($this->options['execute'] ? 'deleted' : 'planned') . ": {$stats['products_deleted']}; protected: {$stats['products_protected']}\n";
        echo "Image files " . ($this->options['execute'] ? 'deleted' : 'planned') . ": {$stats['images_deleted']}; media rows: {$stats['media_rows_deleted']}; errors: {$stats['errors']}\n";
        if (!$this->options['execute']) {
            echo "Nothing was deleted. Re-run with --execute after reviewing the audit log.\n";
        }
    }

    private function table(string $name): string
    {
        return $this->prefix . $name;
    }

    private function tableExists(string $name): bool
    {
        $table = $this->table($name);
        return in_array($table, $this->getTables(), true);
    }

    private function getTables(): array
    {
        if ($this->tableList === null) {
            $this->tableList = array_values($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        }
        return $this->tableList;
    }

    private function getColumns(string $name): array
    {
        if (isset($this->columns[$name])) {
            return $this->columns[$name];
        }
        $table = $this->table($name);
        if (!$this->tableExists($name)) {
            return $this->columns[$name] = [];
        }
        $quoted = str_replace('`', '``', $table);
        return $this->columns[$name] = array_values(array_column($this->db->query("SHOW COLUMNS FROM `{$quoted}`")->fetchAll(), 'Field'));
    }

    private function getColumnsByFullName(string $table): array
    {
        if (isset($this->fullColumns[$table])) {
            return $this->fullColumns[$table];
        }
        $quoted = str_replace('`', '``', $table);
        return $this->fullColumns[$table] = array_values(array_column($this->db->query("SHOW COLUMNS FROM `{$quoted}`")->fetchAll(), 'Field'));
    }
}

function usage(): never
{
    echo <<<TXT
VirtueMart 4 cleanup utility v{VERSION}

Usage:
  php cleanup-cli.php --config=site-config.php [options]

Options:
  --mode=orphan-images|old-products|all   Operation (default: orphan-images)
  --cutoff=YYYY-MM-DD                    Delete products created before this date
  --execute                              Perform deletions (default is dry-run)
  --yes                                  Skip the execute confirmation prompt
  --limit=N                              Limit candidate products (for staged runs)
  --batch-size=N                         Products per DB transaction (default: 100)
  --site-label=NAME                      Label saved with the SQLite audit run
  --audit-db=PATH                        Override configured SQLite audit database
  --media-root=PATH                      Override configured media roots with one root
  --help                                 Show this help

TXT;
    exit(0);
}

function parseOptions(array $argv): array
{
    $options = [
        'config' => null, 'mode' => 'orphan-images', 'cutoff' => null,
        'execute' => false, 'yes' => false, 'limit' => null,
        'batch-size' => 100, 'site-label' => null, 'audit-db' => null, 'media-root' => null,
    ];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            usage();
            exit(0);
        }
        if (!str_starts_with($arg, '--')) {
            throw new InvalidArgumentException("Unexpected argument: {$arg}");
        }
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
        if (in_array($key, ['execute', 'yes'], true)) {
            if ($value !== null) {
                throw new InvalidArgumentException("--{$key} does not take a value.");
            }
            $options[$key] = true;
            continue;
        }
        if (!array_key_exists($key, $options) || $value === null || $value === '') {
            throw new InvalidArgumentException("Invalid option: {$arg}");
        }
        $options[$key] = $value;
    }
    if (!in_array($options['mode'], ['orphan-images', 'old-products', 'all'], true)) {
        throw new InvalidArgumentException('Invalid --mode.');
    }
    if ($options['cutoff'] !== null) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $options['cutoff']);
        if (!$date || $date->format('Y-m-d') !== $options['cutoff']) {
            throw new InvalidArgumentException('--cutoff must be a valid YYYY-MM-DD date.');
        }
    }
    foreach (['limit', 'batch-size'] as $key) {
        if ($options[$key] !== null && (!ctype_digit((string)$options[$key]) || (int)$options[$key] < 1)) {
            throw new InvalidArgumentException("--{$key} must be a positive integer.");
        }
        if ($options[$key] !== null) {
            $options[$key] = (int)$options[$key];
        }
    }
    return $options;
}

if (!defined('VM4_CLEANUP_LIBRARY_ONLY')) {
    try {
        $options = parseOptions($argv);
        if ($options['config'] === null || !is_file($options['config']) || !is_readable($options['config'])) {
            throw new RuntimeException('Pass a readable --config=site-config.php file.');
        }
        $config = require $options['config'];
        if (!is_array($config)) {
            throw new RuntimeException('The config file must return an array.');
        }
        if ($options['execute'] && !$options['yes']) {
            fwrite(STDERR, "This will permanently delete database rows and files. Type DELETE to continue: ");
            $answer = trim((string)fgets(STDIN));
            if ($answer !== 'DELETE') {
                echo "Cancelled; no changes were made.\n";
                exit(0);
            }
        }
        (new Cleanup($config, $options))->run();
    } catch (Throwable $e) {
        fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
