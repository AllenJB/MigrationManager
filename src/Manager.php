<?php
declare(strict_types = 1);

namespace AllenJB\MigrationManager;
use AllenJB\MigrationManager\Exception\MigrationException;
use AllenJB\MigrationManager\Exception\MigrationIntegrityException;
use AllenJB\MigrationManager\Exception\MigrationLockException;

/**
 * Simple PDO-based Migrations Manager.
 *
 * Migrations are primarily raw SQL queries, but may also contain PHP logic (eg. for migrating data between tables / fields)
 *
 * Migrations are non-reversible.
 *
 * We could attempt to execute migrations on the same database simultaneously from different servers
 * (multiple web servers connecting to the same DB server, where a deployment script attempts to execute
 * migrations on all web servers, possibly at the same time).
 *
 * Migration filenames must be in the format: YYYY-mm-dd_HHii_<name>.php
 *
 * We can 'schedule' migrations to be performed in the future by giving them a date after today.
 *
 * @package AllenJB\MigrationManager
 */
class Manager
{

    /**
     * @var \PDO Database connection
     */
    protected $db;

    /**
     * @var string Path where migrations are found
     */
    protected $migrationsPath;

    /**
     * @var string Name of the migration management table
     */
    protected $tableName;

    /**
     * @var array[] Migrations that have either already been executed or should be executed
     */
    protected $currentMigrations = [];

    /**
     * @var array[] Migrations that should be executed now
     */
    protected $migrationsToExecute = [];

    /**
     * @var array[] Migrations that should be executed in the future
     */
    protected $futureMigrations = [];

    /**
     * @var array[] Migrations that have been executed
     */
    protected $executedMigrations = [];


    /**
     * Manager constructor.
     *
     * @param \PDO $pdo Initialized PDO connection
     * @param string $pathToMigrations Path to migrations
     * @param string $mgrTableName Table used for managing migrations
     * @throws MigrationException
     */
    public function __construct(\PDO $pdo, string $pathToMigrations, string $mgrTableName)
    {
        $this->db = $pdo;
        $this->migrationsPath = $pathToMigrations;
        $this->tableName = $mgrTableName;

        $this->initializeTable();
        $this->lock();
        try {
            $this->loadMigrations();
            $this->loadExecutedMigrations();
            $this->selectMigrationsToExecute();
        } catch (MigrationException $e) {
            $this->unlock();
            throw $e;
        }
    }


    protected function initializeTable() : void
    {
        // Create migrations management table if it doesn't already exist
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->tableName}` (
                `name` VARCHAR(512) NOT NULL COLLATE 'utf8mb4_unicode_ci',
                `dt_started` DATETIME NOT NULL,
                `dt_finished` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`name`),
                INDEX `dt_finished` (`dt_finished`)
            )
            COLLATE='utf8mb4_unicode_ci'
            ENGINE=InnoDB";
        $this->db->exec($sql);
    }


    protected function lock() : bool
    {
        $sql = "INSERT INTO `{$this->tableName}`
          (`name`, `dt_started`, `dt_finished`) VALUES
          ('/lock', NOW(), NULL);";
        try {
            $affected = $this->db->exec($sql);
            if ($affected < 1) {
                throw new MigrationLockException("Could not create lock record (no rows affected)");
            }
        } catch (\Exception $e) {
            throw new MigrationLockException("Failed to lock migrations (already running?)", 0, $e);
        }

        $sql = "SELECT * FROM `{$this->tableName}` WHERE dt_finished IS NULL AND `name` != '/lock'";
        $rs = $this->db->query($sql);
        if ($rs->rowCount() > 0) {
            $firstEntry = $rs->fetch();
            throw new MigrationLockException("An unfinished migration was detected. Manual intervention required: ". $firstEntry->name);
        }

        return true;
    }


    public function unlock() : void
    {
        $sql = "DELETE FROM `{$this->tableName}` WHERE `name` = '/lock'";
        $this->db->exec($sql);
    }


    protected function loadMigrations() : void
    {
        $dtNow = new \DateTimeImmutable("midnight tomorrow");
        $usedClassNames = [];

        $dir = dir($this->migrationsPath);
        while (false !== ($file = $dir->read())) {
            if (substr($file, -4) !== '.php') {
                continue;
            }

            if (!preg_match('/^(?P<date>20[0-9]{2}\-[0-9]{2}\-[0-9]{2}_[0-9]{4})_/', $file, $matches)) {
                throw new \UnexpectedValueException("A migration file uses an invalid filename format: {$file} (missing date part)");
            }
            $datePart = $matches['date'];
            $dtMigration = \DateTimeImmutable::createFromFormat('!Y-m-d_Hi', $datePart);
            $className = str_replace($datePart .'_', '', $file);
            $className = substr($className, 0, -4);

            $ciClassName = strtolower($className);
            if (array_key_exists($ciClassName, $usedClassNames)) {
                $dupedIn = $usedClassNames[$ciClassName];
                throw new MigrationIntegrityException("Duplicate migration name: {$className} (file: {$file}, already declared in: {$dupedIn})");
            }
            $usedClassNames[$ciClassName] = $file;

            $fqClassName = '\\'. $className;
            require_once ($this->migrationsPath .'/'. $file);
            if (!class_exists($fqClassName)) {
                throw new MigrationIntegrityException("Migration class not found: ". $fqClassName ." (file: ". $file .")");
            }

            if ($dtMigration < $dtNow) {
                $this->currentMigrations[] = [
                    'name' => $file,
                    'className' => $className,
                ];
            } else {
                $dtExecute = $dtMigration->setTime(0, 0, 0, 0);
                $this->futureMigrations[] = [
                    'name' => $file,
                    'className' => $className,
                    'dtExecute' => $dtExecute,
                ];
            }
        }
    }


    protected function loadExecutedMigrations() : void
    {
        $sql = "SELECT * FROM `{$this->tableName}` ORDER BY dt_finished ASC";
        $rs = $this->db->query($sql);
        foreach ($rs as $entry) {
            if ($entry->name === '/lock') {
                continue;
            }

            // We use the execution date as the key so we list items in the order they were executed
            $dtExecuted = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $entry->dt_finished);
            $key = $dtExecuted->format('YmdHis') .'_'. $entry->name;
            $this->executedMigrations[$key] = [
                'name' => $entry->name,
                'dtExecute' => $dtExecuted
            ];
        }
    }


    protected function selectMigrationsToExecute() : void
    {
        $executedMigrationsNames = [];
        foreach ($this->executedMigrations as $migration) {
            $executedMigrationsNames[] = $migration['name'];
        }

        foreach ($this->currentMigrations as $migration) {
            if (in_array($migration['name'], $executedMigrationsNames)) {
                continue;
            }

            $this->migrationsToExecute[] = $migration;
        }
    }


    public function executeMigrations() : void
    {
        foreach ($this->migrationsToExecute as $key => $migration) {
            $this->executeMigration($migration);

            $dtExecuted = new \DateTimeImmutable();
            unset($this->migrationsToExecute[$key]);
            $this->executedMigrations[] = [
                'name' => $migration['name'],
                'dtExecute' => $dtExecuted,
            ];
        }
    }


    protected function executeMigration(array $migrationEntry) : void
    {
        $sql = "INSERT INTO `{$this->tableName}` 
          (`name`, `dt_started`, `dt_finished`) VALUES
          (:name, NOW(), NULL);";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['name' => $migrationEntry['name']]);

        $className = '\\'. $migrationEntry['className'];
        require_once ($this->migrationsPath .'/'. $migrationEntry['name']);
        if (!class_exists($className)) {
            throw new MigrationIntegrityException("Migration class not found: ". $migrationEntry['className'] ." (file: ". $migrationEntry['name'] .")");
        }

        /**
         * @var AbstractMigration $migration
         */
        $migration = new $className($this->db);
        $migration->up();

        $sql = "UPDATE `{$this->tableName}` SET dt_finished = NOW()
          WHERE `name` = :name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['name' => $migrationEntry['name']]);
    }


    public function executedMigrations() : array
    {
        return $this->executedMigrations;
    }


    public function pendingMigrations() : array
    {
        return $this->migrationsToExecute;
    }


    public function futureMigrations() : array
    {
        return $this->futureMigrations;
    }

}
