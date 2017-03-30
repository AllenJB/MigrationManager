<?php

namespace AllenJB\MigrationManager;

abstract class AbstractMigration
{

    protected $db;


    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }


    public abstract function up();

}
