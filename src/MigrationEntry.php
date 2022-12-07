<?php
declare(strict_types=1);

namespace AllenJB\MigrationManager;

class MigrationEntry
{
    public string $fileName;

    public string $className;

    public \DateTimeImmutable $shouldExecuteAt;

    public ?\DateTimeImmutable $actuallyExecutedAt = null;


    public static function createFromFilename(string $filename): self
    {
        if (! preg_match('/^(?P<date>20[0-9]{2}\-[0-9]{2}\-[0-9]{2}_[0-9]{4})_/', $filename, $matches)) {
            throw new \UnexpectedValueException("A migration file uses an invalid filename format: {$filename} (missing date part)");
        }
        $datePart = $matches['date'];
        $dtMigration = \DateTimeImmutable::createFromFormat('!Y-m-d_Hi', $datePart);
        if ($dtMigration === false) {
            throw new \UnexpectedValueException("Migration date in filename is not a valid date");
        }
        $dtMigration = $dtMigration->setTime(0, 0, 0, 0);

        $className = str_replace($datePart . '_', '', $filename);
        $className = substr($className, 0, -4);

        $entry = new self();
        $entry->fileName = $filename;
        $entry->className = $className;
        $entry->shouldExecuteAt = $dtMigration;

        return $entry;
    }


    public static function createFromExecutionRecord(string $filename, \DateTimeImmutable $dtExecuted): self
    {
        $entry = self::createFromFilename($filename);
        $entry->actuallyExecutedAt = $dtExecuted;

        return $entry;
    }
}
